<?php

namespace App\Modules\FieldSales\Services;

use App\Models\User;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Accounting\Services\InventoryPosting;
use App\Modules\Catalogue\Models\ProductUnit;
use App\Modules\FieldSales\Models\SyncIssue;
use App\Modules\FieldSales\Models\Trip;
use App\Modules\FieldSales\Models\TripLine;
use App\Modules\FieldSales\Models\TripLoad;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Services\DocumentNumber;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Inventory\Support\StockStatus;
use App\Modules\Organization\Models\Branch;
use App\Modules\Organization\Models\Warehouse;
use App\Modules\Sales\Models\Sale;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\Sales\Models\SalePayment;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * A salesperson works out of a van: a warehouse of their own. The warehouse issues stock onto the van, the salesperson
 * sells from it (the normal sale flow, so stock, cost and accounts all stay right), and at check-in what is left
 * comes back. Anything that neither sold nor came back is written off at cost and shown as a shortage.
 */
class TripService
{
    public function __construct(private StockService $stock, private InventoryPosting $posting, private DocumentNumber $numbers, private \App\Modules\Fleet\Services\ImprestService $imprest)
    {
    }

    /** Creates the salesperson's login and their van in one step. */
    public function createRep(array $data, int $storeId): User
    {
        return DB::transaction(function () use ($data, $storeId) {
            $branch = Branch::findOrFail($data['branch_id']);

            app(PermissionRegistrar::class)->setPermissionsTeamId($storeId);

            $role = Role::firstOrCreate(['name' => 'Field Sales', 'guard_name' => 'web', 'store_id' => $storeId]);

            if ($role->wasRecentlyCreated) {
                $role->syncPermissions(Permissions::roleTemplates()['Field Sales']);
            }

            $user = User::create([
                'name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null, 'password' => $data['password'],
                'store_id' => $storeId, 'branch_id' => $branch->id,
            ]);
            $user->assignRole($role);

            Warehouse::create([
                'store_id' => $storeId, 'branch_id' => $branch->id, 'name' => "{$user->name}'s van", 'code' => 'VAN-'.$user->id,
                'type' => 'van', 'assigned_user_id' => $user->id, 'is_default' => false, 'is_active' => true,
            ]);

            return $user;
        });
    }

    public function openTripFor(User|int $user): ?Trip
    {
        $id = $user instanceof User ? $user->id : $user;
        $van = Warehouse::vanOf($id);

        return $van ? Trip::withoutGlobalScopes()->where('van_warehouse_id', $van->id)->where('status', 'open')->first() : null;
    }

    /**
     * Puts stock on the van. The first issue opens the trip; later ones top it up.
     *
     * @param  array<int, array{product_id:int, unit_id:int, quantity:int}>  $lines
     */
    public function issue(User $rep, Warehouse $from, array $lines, ?string $note, User $actor, ?int $vehicleId = null): Trip
    {
        return DB::transaction(function () use ($rep, $from, $lines, $note, $actor, $vehicleId) {
            $van = Warehouse::vanOf($rep->id) ?? throw ValidationException::withMessages(['rep_id' => 'That user is not a salesperson.']);

            if (! $rep->is_active) {
                throw ValidationException::withMessages(['rep_id' => 'This salesperson’s account is disabled.']);
            }

            if ($from->isVan() || ! $from->is_active) {
                throw ValidationException::withMessages(['from_warehouse_id' => 'Issue stock from an active warehouse.']);
            }

            if ($from->branch_id !== $van->branch_id) {
                throw ValidationException::withMessages(['from_warehouse_id' => 'Issue stock from a warehouse in the salesperson’s own branch.']);
            }

            if ($vehicleId) {
                $vehicle = Vehicle::lockForUpdate()->find($vehicleId) ?? throw ValidationException::withMessages(['vehicle_id' => 'That vehicle was not found.']);

                if ($vehicle->status !== 'active') {
                    throw ValidationException::withMessages(['vehicle_id' => "{$vehicle->reg_no} is not available (it is in the workshop or retired)."]);
                }

                $busy = Trip::withoutGlobalScopes()->where('vehicle_id', $vehicle->id)->where('status', 'open')->where('van_warehouse_id', '!=', $van->id)->first();

                if ($busy) {
                    throw ValidationException::withMessages(['vehicle_id' => "{$vehicle->reg_no} is already out on trip {$busy->number}."]);
                }
            }

            $trip = Trip::withoutGlobalScopes()->where('van_warehouse_id', $van->id)->where('status', 'open')->lockForUpdate()->first()
                ?? Trip::create([
                    'store_id' => $van->store_id, 'branch_id' => $van->branch_id, 'number' => $this->numbers->next($van->store_id, 'TRP'),
                    'rep_id' => $rep->id, 'van_warehouse_id' => $van->id, 'status' => 'open', 'opened_at' => now(),
                    'opened_by' => $actor->id, 'note' => $note, 'vehicle_id' => $vehicleId,
                ]);

            if ($vehicleId && ! $trip->vehicle_id) {
                $trip->update(['vehicle_id' => $vehicleId]);
            }

            $load = TripLoad::create([
                'store_id' => $van->store_id, 'trip_id' => $trip->id, 'kind' => 'issue', 'warehouse_id' => $from->id,
                'user_id' => $actor->id, 'note' => $note,
            ]);

            foreach ($this->toBaseUnits($lines, 'lines') as $productId => $base) {
                $load->lines()->create(['product_id' => $productId, 'status' => 'saleable', 'quantity' => $base]);

                $meta = ['source' => $load, 'reference' => $trip->number, 'note' => $note, 'user_id' => $actor->id];
                $this->stock->move($from, $productId, StockStatus::SALEABLE, -$base, 'trip_issue', [...$meta, 'idempotency_key' => "tload:{$load->id}:{$productId}:out"]);
                $this->stock->move($van, $productId, StockStatus::SALEABLE, $base, 'trip_issue', [...$meta, 'idempotency_key' => "tload:{$load->id}:{$productId}:in"]);

                $line = TripLine::firstOrCreate(['trip_id' => $trip->id, 'product_id' => $productId]);
                $line->increment('issued', $base);
            }

            return $trip;
        });
    }

    /**
     * Stock coming back from the road. With $close the trip ends: whatever is still on the van is written off as a
     * shortage, and the cash handed in is compared with the cash the salesperson collected.
     *
     * @param  array<int, array{product_id:int, unit_id:int, quantity:int, status?:string}>  $returns
     */
    public function checkIn(int $tripId, ?Warehouse $to, array $returns, bool $close, ?int $cashHandedIn, ?string $note, User $actor, ?int $imprestReturned = null): Trip
    {
        return DB::transaction(function () use ($tripId, $to, $returns, $close, $cashHandedIn, $note, $actor, $imprestReturned) {
            $trip = Trip::lockForUpdate()->findOrFail($tripId);

            abort_unless($trip->isOpen(), 422, 'This trip is already closed.');

            $van = Warehouse::withoutGlobalScopes()->findOrFail($trip->van_warehouse_id);

            if ($returns !== [] && ($to === null || $to->isVan() || ! $to->is_active || $to->branch_id !== $van->branch_id)) {
                throw ValidationException::withMessages(['to_warehouse_id' => 'Choose an active warehouse in the same branch to receive the stock.']);
            }

            $grouped = [];
            foreach ($returns as $r) {
                $status = $r['status'] ?? StockStatus::SALEABLE;
                $grouped[$status][] = $r;
            }

            // The load document is only created if something actually comes off the van.
            $load = null;
            $newLoad = function () use (&$load, $trip, $to, $van, $actor, $note) {
                return $load ??= TripLoad::create([
                    'store_id' => $trip->store_id, 'trip_id' => $trip->id, 'kind' => 'return', 'warehouse_id' => ($to ?? $van)->id,
                    'user_id' => $actor->id, 'note' => $note,
                ]);
            };
            $meta = fn () => ['source' => $newLoad(), 'reference' => $trip->number, 'note' => $note, 'user_id' => $actor->id];

            $damaged = [];

            foreach ($grouped as $status => $rows) {
                foreach ($this->toBaseUnits($rows, 'returns') as $productId => $base) {
                    $line = TripLine::firstOrCreate(['trip_id' => $trip->id, 'product_id' => $productId]);
                    $isDamaged = $status === StockStatus::DAMAGED;
                    $type = $isDamaged ? 'trip_damaged' : 'trip_return';
                    $key = "tload:{$newLoad()->id}:{$productId}:{$status}";

                    $newLoad()->lines()->create(['product_id' => $productId, 'status' => $status, 'quantity' => $base]);
                    $this->stock->move($van, $productId, StockStatus::SALEABLE, -$base, $type, [...$meta(), 'idempotency_key' => "$key:out"]);
                    $this->stock->move($to, $productId, $isDamaged ? StockStatus::DAMAGED : StockStatus::SALEABLE, $base, $type, [...$meta(), 'idempotency_key' => "$key:in"]);

                    if ($isDamaged) {
                        $line->increment('damaged', $base);
                        $damaged[$productId] = $base;
                    } else {
                        $line->increment('returned', $base);
                    }
                }
            }

            if ($damaged) {
                $this->posting->tripLoss($trip->store_id, $trip->branch_id, "Trip {$trip->number}: damaged stock returned", "trip:{$trip->id}:load:{$load->id}:damaged", $load, $damaged, $actor);
            }

            if ($close) {
                $this->closeOut($trip, $van, $newLoad, $cashHandedIn ?? 0, $note, $actor, $imprestReturned);
            }

            return $trip->refresh();
        });
    }

    /** What the salesperson should hand in: cash they took since the trip began, less any payments voided. */
    public function cashExpected(Trip $trip): int
    {
        $collected = (int) SalePayment::withoutGlobalScopes()
            ->where('store_id', $trip->store_id)->where('received_by', $trip->rep_id)
            ->where('method', 'cash')->whereNull('voided_at')
            ->where('paid_at', '>=', $trip->opened_at)
            ->when($trip->closed_at, fn ($q, $at) => $q->where('paid_at', '<=', $at))
            ->sum('amount');

        // Running costs come out of the driver's imprest, never out of the sales they collect.
        return $collected;
    }

    private function imprestBlock(Trip $trip): array
    {
        $items = \App\Modules\Fleet\Models\Imprest::withoutGlobalScopes()->with('issuer:id,name')->where('trip_id', $trip->id)->orderBy('id')->get();
        $now = $this->imprest->summary($trip);

        return [
            ...($trip->isOpen() ? $now : [
                'issued' => (int) $trip->imprest_issued, 'spent' => (int) $trip->imprest_spent,
                'balance' => (int) $trip->imprest_issued - (int) $trip->imprest_spent,
                'returned' => (int) $trip->imprest_returned, 'reimbursed' => (int) $trip->imprest_reimbursed, 'unreturned' => (int) $trip->imprest_unreturned,
            ]),
            'items' => $items->map(fn ($i) => [
                'id' => $i->id, 'number' => $i->number, 'amount' => $i->amount, 'method' => $i->method, 'status' => $i->status,
                'note' => $i->note, 'at' => $i->created_at, 'by' => $i->issuer?->name,
            ])->values(),
        ];
    }

    /** What the salesperson's phone has not sent yet, and what the server turned down. A trip should not close over either. */
    public function syncState(Trip $trip): array
    {
        return [
            'pending' => (int) $trip->device_pending, 'seen_at' => $trip->device_seen_at,
            'issues' => SyncIssue::withoutGlobalScopes()->where('trip_id', $trip->id)->where('status', 'open')->count(),
        ];
    }

    /** Expenses paid in cash on this trip before imprest existed. Kept for old trips; new expenses use the imprest. */
    public function cashSpent(Trip $trip): int
    {
        return (int) Expense::withoutGlobalScopes()->where('trip_id', $trip->id)->where('method', 'cash')->where('status', 'posted')->sum('amount');
    }

    /** Everything the trip screens show: per-product loaded/sold/back, sales, money, and the load history. */
    public function report(Trip $trip): array
    {
        $trip->loadMissing(['rep:id,name,phone', 'van:id,name', 'vehicle:id,reg_no,nickname,type']);

        $sold = DB::table('sale_lines')->join('sales', 'sales.id', '=', 'sale_lines.sale_id')
            ->where('sales.trip_id', $trip->id)->where('sales.status', 'completed')
            ->groupBy('sale_lines.product_id')
            ->selectRaw('sale_lines.product_id, SUM(sale_lines.quantity * sale_lines.factor) as units, SUM(sale_lines.line_total) as revenue')
            ->get()->keyBy('product_id');

        $tripLines = TripLine::with('product:id,name,sku,size_label')->where('trip_id', $trip->id)->get();

        // Base unit and biggest pack per product, so quantities can read "3 Crates + 4 Bottles" on screen.
        $packs = ProductUnit::with('unit:id,name')->whereIn('product_id', $tripLines->pluck('product_id'))->where('is_active', true)->get()->groupBy('product_id');

        $lines = $tripLines->map(function (TripLine $l) use ($sold, $packs) {
            $units = (int) ($sold[$l->product_id]->units ?? 0);
            $mine = $packs[$l->product_id] ?? collect();
            $base = $mine->firstWhere('is_base', true);
            $big = $mine->where('is_base', false)->sortByDesc('factor')->first();

            return [
                'product_id' => $l->product_id, 'name' => $l->product?->name, 'sku' => $l->product?->sku, 'size_label' => $l->product?->size_label,
                'base_unit' => $base?->unit?->name, 'base_unit_id' => $base?->unit_id, 'pack' => $big ? ['name' => $big->unit?->name, 'factor' => $big->factor] : null,
                'issued' => $l->issued, 'sold' => $units, 'revenue' => (int) ($sold[$l->product_id]->revenue ?? 0),
                'returned' => $l->returned, 'damaged' => $l->damaged, 'shortage' => $l->shortage,
                'with_rep' => max(0, $l->issued - $units - $l->returned - $l->damaged - $l->shortage),
            ];
        })->sortBy('name')->values();

        $sales = Sale::withoutGlobalScopes()->where('trip_id', $trip->id)->with('customer:id,name')->orderByDesc('sold_at')->orderByDesc('id')->get();
        $done = $sales->where('status', 'completed');

        $methods = SalePayment::withoutGlobalScopes()->whereIn('sale_id', $sales->pluck('id'))->whereNull('voided_at')
            ->groupBy('method')->selectRaw('method, SUM(amount) as total')->pluck('total', 'method');

        $loads = TripLoad::with(['lines.product:id,name', 'warehouse:id,name', 'user:id,name'])->where('trip_id', $trip->id)->orderBy('id')->get();

        return [
            'trip' => $trip->only(['id', 'number', 'status', 'opened_at', 'closed_at', 'note', 'close_note', 'branch_id', 'van_warehouse_id']),
            'rep' => $trip->rep, 'van' => $trip->van, 'vehicle' => $trip->vehicle,
            'lines' => $lines,
            'totals' => [
                'issued' => (int) $lines->sum('issued'), 'sold' => (int) $lines->sum('sold'), 'with_rep' => (int) $lines->sum('with_rep'),
                'returned' => (int) $lines->sum('returned'), 'damaged' => (int) $lines->sum('damaged'), 'shortage' => (int) $lines->sum('shortage'),
            ],
            'money' => [
                'orders' => $done->count(), 'sales' => (int) $done->sum('total'), 'collected' => (int) $done->sum('amount_paid'),
                'on_credit' => (int) $done->sum('balance_due'),
                'cash' => (int) ($methods['cash'] ?? 0), 'transfer' => (int) ($methods['transfer'] ?? 0), 'pos' => (int) ($methods['pos'] ?? 0),
                'cash_spent' => $trip->isOpen() ? $this->cashSpent($trip) : (int) Expense::withoutGlobalScopes()->where('trip_id', $trip->id)->where('method', 'cash')->where('status', 'posted')->sum('amount'),
                'cash_expected' => $trip->isOpen() ? $this->cashExpected($trip) : $trip->cash_expected,
                'cash_handed_in' => $trip->cash_handed_in, 'cash_variance' => $trip->cash_variance,
            ],
            'sales' => $sales->take(100)->map(fn (Sale $s) => [
                'id' => $s->id, 'number' => $s->number, 'sold_at' => $s->sold_at, 'status' => $s->status, 'payment_status' => $s->payment_status,
                'total' => $s->total, 'amount_paid' => $s->amount_paid, 'balance_due' => $s->balance_due, 'customer' => $s->customer?->name,
            ])->values(),
            'sync' => $this->syncState($trip),
            'imprest' => $this->imprestBlock($trip),
            'expenses' => Expense::withoutGlobalScopes()->with('category:id,name')->where('trip_id', $trip->id)->orderByDesc('id')->get()->map(fn (Expense $e) => [
                'id' => $e->id, 'number' => $e->number, 'category' => $e->category?->name, 'amount' => $e->amount, 'method' => $e->method,
                'paid_to' => $e->paid_to, 'description' => $e->description, 'status' => $e->status, 'date' => $e->expense_date->toDateString(),
            ])->values(),
            'loads' => $loads->map(fn (TripLoad $l) => [
                'id' => $l->id, 'kind' => $l->kind, 'at' => $l->created_at, 'by' => $l->user?->name, 'warehouse' => $l->warehouse?->name, 'note' => $l->note,
                'lines' => $l->lines->map(fn ($x) => ['product' => $x->product?->name, 'status' => $x->status, 'quantity' => $x->quantity])->values(),
            ])->values(),
        ];
    }

    private function closeOut(Trip $trip, Warehouse $van, \Closure $newLoad, int $cashHandedIn, ?string $note, User $actor, ?int $imprestReturned = null): void
    {
        $left = StockBalance::withoutGlobalScopes()->where('warehouse_id', $van->id)->where('status', StockStatus::SALEABLE)
            ->where('quantity', '>', 0)->lockForUpdate()->pluck('quantity', 'product_id');

        $short = [];

        foreach ($left as $productId => $qty) {
            $line = TripLine::firstOrCreate(['trip_id' => $trip->id, 'product_id' => $productId]);
            $load = $newLoad();

            $load->lines()->create(['product_id' => $productId, 'status' => 'shortage', 'quantity' => $qty]);
            $this->stock->move($van, $productId, StockStatus::SALEABLE, -$qty, 'trip_shortage', [
                'source' => $load, 'reference' => $trip->number, 'note' => 'Not returned at check-in', 'user_id' => $actor->id,
                'idempotency_key' => "tload:{$load->id}:{$productId}:shortage",
            ]);
            $line->increment('shortage', $qty);
            $short[$productId] = $qty;
        }

        if ($short) {
            $this->posting->tripLoss($trip->store_id, $trip->branch_id, "Trip {$trip->number}: stock not returned", "trip:{$trip->id}:shortage", $trip, $short, $actor);
        }

        $this->imprest->settle($trip, $imprestReturned, $actor);

        $expected = $this->cashExpected($trip->setAttribute('closed_at', now()));

        $trip->update([
            'status' => 'closed', 'closed_at' => now(), 'closed_by' => $actor->id, 'close_note' => $note,
            'cash_expected' => $expected, 'cash_handed_in' => $cashHandedIn, 'cash_variance' => $cashHandedIn - $expected,
        ]);
    }

    /**
     * Turns {product, unit, quantity} rows into base units per product, merging repeats.
     *
     * @return array<int, int> product_id => base units
     */
    private function toBaseUnits(array $rows, string $field): array
    {
        $out = [];

        foreach ($rows as $i => $row) {
            $unit = ProductUnit::where('product_id', $row['product_id'])->where('unit_id', $row['unit_id'])->where('is_active', true)->first()
                ?? throw ValidationException::withMessages(["$field.$i.unit_id" => 'That product is not sold in that unit.']);

            $out[$row['product_id']] = ($out[$row['product_id']] ?? 0) + $row['quantity'] * $unit->factor;
        }

        return $out;
    }
}
