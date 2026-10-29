<?php

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\Accounting\Services\FiscalPeriods;
use App\Modules\Accounting\Services\InventoryCosting;
use App\Modules\Accounting\Services\SalesPosting;
use App\Modules\Catalogue\Models\Product;
use App\Modules\Containers\Services\ContainerService;
use App\Modules\Catalogue\Models\ProductUnit;
use App\Modules\Catalogue\Services\PriceResolver;
use App\Modules\FieldSales\Models\Trip;
use App\Modules\Inventory\Services\DocumentNumber;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Inventory\Support\StockStatus;
use App\Modules\Organization\Models\Warehouse;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\MixLayout;
use App\Modules\Sales\Models\PosTerminal;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SalePayment;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Validation\ValidationException;

/**
 * Selling is ONE transaction: stock leaves the warehouse, the cost of it is taken out of inventory, the sale and its
 * payments are booked, and the customer's balance moves. If any step fails (not enough stock, credit limit, closed
 * period) nothing happens at all.
 *
 * Prices always come from the price lists on the server. Charging less than the list price needs sales.discount.
 * Amounts are integer kobo.
 */
class SaleService
{
    public function __construct(
        private StockService $stock,
        private InventoryCosting $costing,
        private SalesPosting $posting,
        private PriceResolver $prices,
        private DocumentNumber $numbers,
        private FiscalPeriods $periods,
        private ContainerService $containers,
    ) {
    }

    /**
     * @param  array{warehouse_id:int, customer_id?:?int, channel?:string, note?:?string, client_ref?:?string,
     *               lines:array<int, array{product_id:int, unit_id:int, quantity:int, unit_price?:?int}>,
     *               empties_returned?:array<int, array{container_type_id:int, quantity:int}>,
     *               payments?:array<int, array{method:string, amount:int, reference?:?string}>}  $data
     * @param  array{offline?:bool, sold_at?:?string, snapshot_at?:?string, reprice?:bool}  $opts
     *         Sales rung up on a phone with no signal arrive later. They keep the time they were made, are priced from what
     *         the phone showed (see priceLines), and carry offline = true so the books can tell.
     */
    public function create(array $data, User $actor, array $opts = []): Sale
    {
        return DB::transaction(function () use ($data, $actor, $opts) {
            $warehouse = Warehouse::where('is_active', true)->findOrFail($data['warehouse_id']);
            $storeId = $warehouse->store_id;

            if (($ref = $data['client_ref'] ?? null) && ($existing = Sale::withoutGlobalScopes()->where('store_id', $storeId)->where('client_ref', $ref)->first())) {
                return $existing;   // a retried request: hand back the sale that was already made
            }

            $trip = $this->tripFor($warehouse, $actor);

            // Card payments must say which machine took them.
            $data['payments'] = array_map(fn ($p) => [...$p, 'pos_terminal_id' => $this->terminalFor($p, $warehouse->branch_id, $actor, $storeId)], $data['payments'] ?? []);

            $lines = $this->assertMixes($this->priceLines($data['lines'], $actor, $opts), $storeId);
            $soldAt = $this->soldAt($opts, $trip, $storeId);
            $subtotal = array_sum(array_column($lines, 'list_total'));
            $total = array_sum(array_column($lines, 'line_total'));
            $paid = array_sum(array_column($data['payments'] ?? [], 'amount'));

            if ($total <= 0) {
                throw ValidationException::withMessages(['lines' => 'A sale must have a value above zero. Use a stock adjustment for free issues.']);
            }

            // Container deposits are charged on top of the goods, so the customer owes total + deposit.
            $customer = $data['customer_id'] ?? null ? Customer::lockForUpdate()->find($data['customer_id']) : null;
            $plan = $this->containers->plan(
                $this->containers->required($lines),
                collect($data['empties_returned'] ?? [])->mapWithKeys(fn ($r) => [(int) $r['container_type_id'] => (int) $r['quantity']])->all(),
                $customer, $storeId,
            );
            $deposit = $plan['deposit'];

            if ($paid > $total + $deposit) {
                throw ValidationException::withMessages(['payments' => 'The payments add up to more than the amount due.']);
            }

            $due = $total + $deposit - $paid;
            $customer = $this->customerFor($data['customer_id'] ?? null, $due, $storeId);

            $sale = Sale::create([
                'store_id' => $storeId, 'branch_id' => $warehouse->branch_id, 'warehouse_id' => $warehouse->id, 'trip_id' => $trip?->id,
                'customer_id' => $customer?->id, 'number' => $this->numbers->next($storeId, 'SAL'),
                'client_ref' => $ref, 'offline' => (bool) ($opts['offline'] ?? false), 'synced_at' => ($opts['offline'] ?? false) ? now() : null, 'channel' => $data['channel'] ?? 'counter', 'status' => 'completed',
                'payment_status' => $due === 0 ? 'paid' : ($paid === 0 ? 'unpaid' : 'partial'),
                'sold_at' => $soldAt, 'subtotal' => $subtotal, 'discount_total' => $subtotal - $total, 'total' => $total, 'deposit_total' => $deposit,
                'amount_paid' => $paid, 'balance_due' => $due, 'note' => $data['note'] ?? null, 'created_by' => $actor->id,
            ]);

            $cogs = 0;

            foreach ($lines as $line) {
                $base = $line['quantity'] * $line['factor'];

                $this->stock->move($warehouse, $line['product_id'], StockStatus::SALEABLE, -$base, 'sale', [
                    'source' => $sale, 'reference' => $sale->number, 'user_id' => $actor->id,
                    'idempotency_key' => "sale:{$sale->id}:{$line['product_id']}:{$line['unit_id']}:{$line['index']}",
                ]);

                $cost = $this->costing->issue($storeId, $line['product_id'], $base);
                $cogs += $cost;

                $sale->lines()->create([
                    'store_id' => $storeId, 'product_id' => $line['product_id'], 'unit_id' => $line['unit_id'], 'factor' => $line['factor'],
                    'quantity' => $line['quantity'], 'list_price' => $line['list_price'], 'unit_price' => $line['unit_price'],
                    'line_total' => $line['line_total'], 'cost_total' => $cost,
                    'mix_layout_id' => $line['mix_layout_id'], 'mix_group' => $line['mix_group'], 'mix_label' => $line['mix_label'] ?? null,
                ]);
            }

            $sale->update(['cogs_total' => $cogs]);
            $this->posting->sale($sale, $actor);
            $this->containers->recordSale($sale, $plan, $customer, $actor);

            foreach ($data['payments'] ?? [] as $p) {
                $this->record($sale, $p, $actor, $soldAt);
            }

            return $sale;
        });
    }

    /** Money received later against a sale that was not fully paid. */
    public function addPayment(int $saleId, array $payment, User $actor): Sale
    {
        return DB::transaction(function () use ($saleId, $payment, $actor) {
            $sale = Sale::lockForUpdate()->findOrFail($saleId);

            abort_unless($sale->status === 'completed', 422, 'A voided sale cannot take payments.');
            abort_if($sale->balance_due <= 0, 422, 'This sale is already fully paid.');

            if ($payment['amount'] > $sale->balance_due) {
                throw ValidationException::withMessages(['amount' => sprintf('Only %s is still owed on this sale.', number_format($sale->balance_due / 100, 2))]);
            }

            $payment['pos_terminal_id'] = $this->terminalFor($payment, $sale->branch_id, $actor, $sale->store_id);
            $this->record($sale, $payment, $actor);

            return $sale;
        });
    }

    /**
     * The card machine behind a card payment. Required whenever the depot has machines set up for this branch and user,
     * so every card payment can be matched to a machine's settlement. Other payment methods carry no machine.
     */
    private function terminalFor(array $p, ?int $branchId, User $actor, int $storeId): ?int
    {
        if (($p['method'] ?? null) !== 'pos') {
            return null;
        }

        $usable = PosTerminal::withoutGlobalScopes()->where('store_id', $storeId)->usableAt($branchId, $actor->id);

        if ($id = $p['pos_terminal_id'] ?? null) {
            return (clone $usable)->whereKey($id)->value('id')
                ?? throw ValidationException::withMessages(['payments' => 'That card terminal is not available for this sale.']);
        }

        if ($usable->exists()) {
            throw ValidationException::withMessages(['payments' => 'Choose which card terminal took the card payment.']);
        }

        return null;
    }

    /** Cancels a sale: the goods go back on the shelf and every journal is reversed. The sale stays on record, marked void. */
    public function void(int $saleId, string $reason, User $actor): Sale
    {
        return DB::transaction(function () use ($saleId, $reason, $actor) {
            $sale = Sale::with(['lines', 'payments'])->lockForUpdate()->findOrFail($saleId);

            abort_unless($sale->status === 'completed', 422, 'This sale is already void.');
            abort_if(str_starts_with((string) $sale->client_ref, \App\Support\Imports\OrderImporter::REF), 422, 'This order was imported from your previous system as history. It never moved stock or posted to the books, so it cannot be voided here.');
            abort_if(
                $sale->trip_id && Trip::withoutGlobalScopes()->whereKey($sale->trip_id)->where('status', 'closed')->exists(),
                422, 'This sale belongs to a trip that is already checked in. Correct the stock with an adjustment instead.',
            );

            $warehouse = Warehouse::withoutGlobalScopes()->findOrFail($sale->warehouse_id);

            foreach ($sale->lines as $line) {
                $this->stock->move($warehouse, $line->product_id, StockStatus::SALEABLE, $line->quantity * $line->factor, 'sale_void', [
                    'source' => $sale, 'reference' => $sale->number, 'note' => "Void: {$reason}", 'user_id' => $actor->id,
                    'idempotency_key' => "sale:{$sale->id}:line:{$line->id}:void",
                ]);
            }

            $this->posting->reverse($sale, $actor, $reason);
            $this->containers->reverseSale($sale, $actor);

            $sale->payments()->whereNull('voided_at')->update(['voided_at' => now()]);
            $sale->update([
                'status' => 'void', 'balance_due' => 0, 'voided_by' => $actor->id, 'voided_at' => now(), 'void_reason' => $reason,
            ]);

            return $sale;
        });
    }

    /**
     * Salespeople sell from their own van, and only while stock is issued to them. Everyone else sells from
     * ordinary warehouses. Returns the open trip the sale belongs to, if any.
     */
    private function tripFor(Warehouse $warehouse, User $actor): ?Trip
    {
        if ($warehouse->isVan()) {
            abort_unless($warehouse->assigned_user_id === $actor->id, 403, 'That van belongs to another salesperson.');

            return Trip::withoutGlobalScopes()->where('van_warehouse_id', $warehouse->id)->where('status', 'open')->first()
                ?? abort(422, 'No stock has been issued to you yet. Ask the warehouse to issue your load first.');
        }

        abort_if(Warehouse::vanOf($actor->id) !== null, 403, 'You sell from your van, not from a warehouse.');

        return null;
    }

    /**
     * The time a sale is booked at. Online sales are "now". An offline sale keeps the time on the phone, but never earlier
     * than the trip began, never in the future, and never inside a closed accounting period (it is booked today instead).
     */
    private function soldAt(array $opts, ?Trip $trip, int $storeId): Carbon
    {
        $at = now();

        if (($opts['offline'] ?? false) && ! empty($opts['sold_at'])) {
            $at = Carbon::parse($opts['sold_at']);

            if ($trip && $at->lt($trip->opened_at)) {
                $at = Carbon::instance($trip->opened_at);
            }

            if ($at->gt(now())) {
                $at = now();
            }

            try {
                $this->periods->assertOpen($storeId, $at->toDateString());
            } catch (HttpException) {
                $at = now();
            }
        }

        return $at;
    }

    private function record(Sale $sale, array $p, User $actor, ?Carbon $at = null): SalePayment
    {
        $payment = SalePayment::create([
            'store_id' => $sale->store_id, 'branch_id' => $sale->branch_id, 'sale_id' => $sale->id,
            'method' => $p['method'], 'pos_terminal_id' => $p['pos_terminal_id'] ?? null, 'amount' => $p['amount'], 'reference' => $p['reference'] ?? null,
            'paid_at' => $at ?? now(), 'received_by' => $actor->id,
        ]);

        $paid = $sale->amount_paid + ($sale->wasRecentlyCreated ? 0 : $p['amount']);
        $due = $sale->total + $sale->deposit_total - $paid;

        if (! $sale->wasRecentlyCreated) {
            $sale->update(['amount_paid' => $paid, 'balance_due' => $due, 'payment_status' => $due === 0 ? 'paid' : 'partial']);
        }

        $this->posting->payment($sale, $payment, $actor);

        return $payment;
    }

    /**
     * Resolves each line to a price in kobo. The list price is the effective price for that unit today;
     * a lower price is a discount and needs permission. A higher price is not allowed.
     */
    private function priceLines(array $input, User $actor, array $opts = []): array
    {
        $products = Product::where('is_active', true)->whereIn('id', array_column($input, 'product_id'))->get()->keyBy('id');
        $units = ProductUnit::where('is_active', true)->whereIn('product_id', $products->keys())->get()->groupBy('product_id');
        $effective = $this->prices->effective()->keyBy('product_unit_id');
        $out = [];

        foreach ($input as $i => $l) {
            $product = $products[$l['product_id']] ?? throw ValidationException::withMessages(["lines.$i.product_id" => 'This product is not available for sale.']);
            $unit = ($units[$product->id] ?? collect())->firstWhere('unit_id', $l['unit_id'])
                ?? throw ValidationException::withMessages(["lines.$i.unit_id" => "{$product->name} is not sold in that unit."]);

            $price = $effective[$unit->id] ?? throw ValidationException::withMessages(["lines.$i.unit_id" => "{$product->name} has no price for that unit. Set it in a price list."]);
            $list = Money::kobo($price['price']);
            $charged = $l['unit_price'] ?? $list;

            // An offline phone quoted a price from its last download. If the price list changed since then, the customer
            // was shown the old price, so honour it as the list price (no discount). A manager retrying a rejected sale
            // can choose to use today's price instead.
            if (($opts['offline'] ?? false) && isset($l['unit_price']) && $charged !== $list) {
                if ($opts['reprice'] ?? false) {
                    $charged = $list;
                } elseif (! empty($opts['snapshot_at']) && $price['updated_at'] && $price['updated_at']->gt(Carbon::parse($opts['snapshot_at']))) {
                    $list = $charged;
                }
            }

            if ($charged > $list) {
                throw ValidationException::withMessages(["lines.$i.unit_price" => 'The price cannot be higher than the list price.']);
            }

            if ($charged < $list && ! $actor->can('sales.discount')) {
                throw ValidationException::withMessages(["lines.$i.unit_price" => 'You do not have permission to sell below the list price.']);
            }

            $out[] = [
                'index' => $i, 'product_id' => $product->id, 'unit_id' => $l['unit_id'], 'factor' => $unit->factor, 'quantity' => $l['quantity'],
                'list_price' => $list, 'unit_price' => $charged, 'list_total' => $list * $l['quantity'], 'line_total' => $charged * $l['quantity'],
                'mix_layout_id' => $l['mix_layout_id'] ?? null, 'mix_group' => $l['mix_group'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * A mixed crate is one layout (say 4 rows of 6) shared out between products. The rules are checked here, not just on
     * the till: every line of the group is sold by the bottle, each in whole rows, the rows add up to exactly one crate,
     * and all the products cost the same per bottle, so mixing never changes what the customer owes.
     */
    private function assertMixes(array $lines, int $storeId): array
    {
        $groups = collect($lines)->filter(fn ($l) => $l['mix_group'] !== null)->groupBy('mix_group');

        if ($groups->isEmpty()) {
            return $lines;
        }

        $layouts = MixLayout::withoutGlobalScopes()->where('store_id', $storeId)->where('is_active', true)->get()->keyBy('id');
        $labels = [];

        foreach ($groups as $group => $rows) {
            $layout = $layouts[$rows->first()['mix_layout_id']] ?? throw ValidationException::withMessages(['lines' => 'That mixed-crate layout is not available.']);

            if ($rows->pluck('mix_layout_id')->unique()->count() > 1) {
                throw ValidationException::withMessages(['lines' => 'A mixed crate can only use one layout.']);
            }

            if ($rows->contains(fn ($l) => (int) $l['factor'] !== 1)) {
                throw ValidationException::withMessages(['lines' => 'Mixed crates are sold by the bottle.']);
            }

            if ($rows->contains(fn ($l) => $l['quantity'] % $layout->row_size !== 0) || $rows->sum('quantity') !== $layout->units) {
                throw ValidationException::withMessages(['lines' => "A {$layout->name} crate is {$layout->row_count} rows of {$layout->row_size} ({$layout->units} bottles), filled completely."]);
            }

            if ($rows->pluck('unit_price')->unique()->count() > 1 || $rows->pluck('list_price')->unique()->count() > 1) {
                throw ValidationException::withMessages(['lines' => 'Only products with the same price can share a mixed crate.']);
            }

            $labels[$group] = $layout->name;
        }

        return array_map(fn ($l) => $l['mix_group'] ? [...$l, 'mix_label' => $labels[$l['mix_group']]] : $l, $lines);
    }

    /** Anything not paid on the spot becomes credit, which needs a named customer with room under their limit. */
    private function customerFor(?int $customerId, int $due, int $storeId): ?Customer
    {
        if ($customerId === null) {
            if ($due > 0) {
                throw ValidationException::withMessages(['customer_id' => 'Choose a customer to sell on credit, or take the full payment now.']);
            }

            return null;
        }

        $customer = Customer::lockForUpdate()->find($customerId);

        if (! $customer || ! $customer->is_active) {
            throw ValidationException::withMessages(['customer_id' => 'That customer is not available.']);
        }

        if ($due > 0) {
            $owing = Customer::outstanding([$customer->id])[$customer->id] ?? 0;

            if ($owing + $due > $customer->credit_limit) {
                throw ValidationException::withMessages(['customer_id' => sprintf(
                    '%s owes %s and the credit limit is %s. Take more payment now or raise the limit.',
                    $customer->name, number_format($owing / 100, 2), number_format($customer->credit_limit / 100, 2),
                )]);
            }
        }

        return $customer;
    }
}
