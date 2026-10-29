<?php

namespace App\Modules\Containers\Services;

use App\Models\User;
use App\Modules\Accounting\Services\Ledger;
use App\Modules\Catalogue\Models\Product;
use App\Modules\Containers\Models\ContainerMovement;
use App\Modules\Containers\Models\ContainerType;
use App\Modules\Organization\Models\Warehouse;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Sale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Returnable bottles and crates.
 *
 * Each customer has a mode:
 *   deposit  pays a deposit for every container taken that is not swapped for an empty on the spot; the deposit comes back
 *            when the container does (walk-in customers are always on deposit)
 *   balance  no money; the depot keeps count of how many containers the customer is holding
 *   none     not tracked
 *
 * Deposits are a liability (Customer Container Deposits). Every change is a row in container_movements and every balance
 * is a sum of those rows. All money is integer kobo.
 */
class ContainerService
{
    public function __construct(private Ledger $ledger)
    {
    }

    /**
     * How many containers of each type a set of sale lines puts into the customer's hands.
     * A bottle container is counted per bottle; a crate container once per full crate sold (a line sold by the crate or bigger).
     *
     * @param  array<int, array{product_id:int, factor:int, quantity:int}>  $lines
     * @return array<int, int>  container_type_id => count
     */
    public function required(array $lines): array
    {
        $products = Product::whereIn('id', array_column($lines, 'product_id'))->with('units:id,product_id,factor')->get()->keyBy('id');
        $need = [];

        foreach ($lines as $l) {
            $p = $products[$l['product_id']] ?? null;

            if (! $p) {
                continue;
            }

            $base = $l['quantity'] * $l['factor'];

            if ($p->bottle_container_id) {
                $need[$p->bottle_container_id] = ($need[$p->bottle_container_id] ?? 0) + $base;
            }

            if ($p->crate_container_id) {
                $size = $p->crateSize();

                if ($size > 0 && $l['factor'] >= $size) {
                    $need[$p->crate_container_id] = ($need[$p->crate_container_id] ?? 0) + intdiv($base, $size);
                }
            }
        }

        return $need;
    }

    /**
     * Works out what a sale does to containers before anything is saved.
     *
     * @param  array<int, int>  $returned  container_type_id => empties handed back at the till
     * @return array{deposit:int, items:array<int, array{sold:int, returned:int, held:int, deposit:int}>}
     */
    public function plan(array $need, array $returned, ?Customer $customer, int $storeId): array
    {
        $mode = $customer?->empties_mode ?? 'deposit';
        $returned = array_filter($returned, fn ($q) => $q > 0);

        if ($mode === 'none') {
            return ['deposit' => 0, 'items' => []];
        }

        $types = ContainerType::whereIn('id', array_unique([...array_keys($need), ...array_keys($returned)]))->get()->keyBy('id');
        $items = [];
        $deposit = 0;

        foreach ($types as $id => $type) {
            $sold = $need[$id] ?? 0;
            $back = $returned[$id] ?? 0;
            $room = $sold + ($mode === 'balance' && $customer ? max(0, $this->holding($storeId, $customer->id, $id)['qty']) : 0);

            if ($back > $room) {
                throw ValidationException::withMessages(['empties_returned' => sprintf(
                    'More %s were handed back than this sale can take (%d). Use "Receive empties" for the extra.', $type->name, $room,
                )]);
            }

            $held = $sold - $back;
            $dep = $mode === 'deposit' ? max(0, $held) * $type->deposit : 0;
            $deposit += $dep;
            $items[$id] = ['sold' => $sold, 'returned' => $back, 'held' => $held, 'deposit' => $dep];
        }

        return ['deposit' => $deposit, 'items' => $items];
    }

    /** Records a sale's container movements. Called inside the sale's transaction, after the sale exists. */
    public function recordSale(Sale $sale, array $plan, ?Customer $customer, User $actor): void
    {
        foreach ($plan['items'] as $typeId => $i) {
            if ($i['sold'] === 0 && $i['returned'] === 0) {
                continue;
            }

            $this->move($sale->store_id, $sale->branch_id, $typeId, 'sale', [
                'warehouse_id' => $sale->warehouse_id, 'party_type' => 'customer', 'party_id' => $customer?->id,
                'qty_party' => $i['held'], 'qty_depot' => $i['returned'], 'deposit' => $i['deposit'],
                'source' => $sale, 'note' => $sale->number, 'user_id' => $actor->id,
            ]);
        }
    }

    /** Undoes a voided sale's container movements with matching opposite rows. */
    public function reverseSale(Sale $sale, User $actor): void
    {
        $rows = ContainerMovement::withoutGlobalScopes()->where('store_id', $sale->store_id)
            ->where('source_type', $sale->getMorphClass())->where('source_id', $sale->id)->where('kind', 'sale')->get();

        foreach ($rows as $r) {
            $this->move($sale->store_id, $r->branch_id, $r->container_type_id, 'sale_void', [
                'warehouse_id' => $r->warehouse_id, 'party_type' => $r->party_type, 'party_id' => $r->party_id,
                'qty_party' => -$r->qty_party, 'qty_depot' => -$r->qty_depot, 'deposit' => -$r->deposit,
                'source' => $sale, 'note' => "Void of {$sale->number}", 'user_id' => $actor->id,
            ]);
        }
    }

    /**
     * A customer hands containers back. Deposit-mode customers get their deposit back, at the average they paid.
     *
     * @param  array{customer_id:?int, container_type_id:int, quantity:int, warehouse_id:int, method?:string, note?:?string}  $d
     */
    public function receive(array $d, User $actor): ContainerMovement
    {
        return DB::transaction(function () use ($d, $actor) {
            [$type, $warehouse] = $this->resolve($d);
            $held = $this->holding($warehouse->store_id, $d['customer_id'] ?? null, $type->id);

            if ($d['quantity'] > $held['qty']) {
                throw ValidationException::withMessages(['quantity' => "Only {$held['qty']} {$type->name} are recorded as held by this customer."]);
            }

            $refund = $held['qty'] > 0 ? intdiv($held['deposit'] * $d['quantity'], $held['qty']) : 0;

            $move = $this->move($warehouse->store_id, $warehouse->branch_id, $type->id, 'return', [
                'warehouse_id' => $warehouse->id, 'party_type' => 'customer', 'party_id' => $d['customer_id'] ?? null,
                'qty_party' => -$d['quantity'], 'qty_depot' => $d['quantity'], 'deposit' => -$refund,
                'note' => $d['note'] ?? null, 'user_id' => $actor->id,
            ]);

            if ($refund > 0) {
                $this->ledger->post($warehouse->store_id, [
                    'date' => now()->toDateString(), 'memo' => "Container deposit refund ({$d['quantity']} × {$type->name})", 'branch_id' => $warehouse->branch_id,
                    'source' => $move, 'posting_key' => "container:{$move->id}", 'user_id' => $actor->id,
                    'lines' => [
                        ['account' => 'container_deposits', 'debit' => $refund],
                        ['account' => ($d['method'] ?? 'cash') === 'cash' ? 'cash' : 'bank', 'credit' => $refund],
                    ],
                ]);
            }

            return $move;
        });
    }

    /** Containers a customer will not bring back. Any deposit held for them is kept by the depot as income. */
    public function writeOff(array $d, User $actor): ContainerMovement
    {
        return DB::transaction(function () use ($d, $actor) {
            [$type, $warehouse] = $this->resolve($d);
            $held = $this->holding($warehouse->store_id, $d['customer_id'] ?? null, $type->id);

            if ($d['quantity'] > $held['qty']) {
                throw ValidationException::withMessages(['quantity' => "Only {$held['qty']} {$type->name} are recorded as held by this customer."]);
            }

            $kept = $held['qty'] > 0 ? intdiv($held['deposit'] * $d['quantity'], $held['qty']) : 0;

            $move = $this->move($warehouse->store_id, $warehouse->branch_id, $type->id, 'write_off', [
                'warehouse_id' => $warehouse->id, 'party_type' => 'customer', 'party_id' => $d['customer_id'] ?? null,
                'qty_party' => -$d['quantity'], 'qty_depot' => 0, 'deposit' => -$kept,
                'note' => $d['note'] ?? 'Not returned', 'user_id' => $actor->id,
            ]);

            if ($kept > 0) {
                $this->ledger->post($warehouse->store_id, [
                    'date' => now()->toDateString(), 'memo' => "Unreturned containers: deposit kept ({$d['quantity']} × {$type->name})", 'branch_id' => $warehouse->branch_id,
                    'source' => $move, 'posting_key' => "container:{$move->id}", 'user_id' => $actor->id,
                    'lines' => [['account' => 'container_deposits', 'debit' => $kept], ['account' => 'other_income', 'credit' => $kept]],
                ]);
            }

            return $move;
        });
    }

    /** Fix the count of empties on a shelf (opening balance, breakages, a stock count). Positive adds, negative removes. */
    public function adjust(array $d, User $actor): ContainerMovement
    {
        [$type, $warehouse] = $this->resolve($d);

        return $this->move($warehouse->store_id, $warehouse->branch_id, $type->id, 'adjustment', [
            'warehouse_id' => $warehouse->id, 'qty_depot' => $d['quantity'], 'note' => $d['note'] ?? null, 'user_id' => $actor->id,
        ]);
    }

    /**
     * Containers moving to or from a supplier (Coca-Cola).
     * "in": full containers arrived, so the depot owes the same number of empties back.
     * "out": empties were sent back, which comes off the shelf and off what is owed.
     */
    public function supplier(array $d, User $actor): ContainerMovement
    {
        [$type, $warehouse] = $this->resolve($d);
        $in = $d['direction'] === 'in';

        return $this->move($warehouse->store_id, $warehouse->branch_id, $type->id, $in ? 'supplier_in' : 'supplier_out', [
            'warehouse_id' => $warehouse->id, 'party_type' => 'supplier', 'party_id' => $d['supplier_id'] ?? null,
            'qty_party' => $in ? $d['quantity'] : -$d['quantity'], 'qty_depot' => $in ? 0 : -$d['quantity'],
            'note' => $d['note'] ?? null, 'user_id' => $actor->id,
        ]);
    }

    /** What a customer (or the walk-in pool when null) holds of one container type, across the whole depot. */
    public function holding(int $storeId, ?int $customerId, int $typeId): array
    {
        $r = DB::table('container_movements')->where('party_type', 'customer')->where('container_type_id', $typeId)
            ->where('store_id', $storeId)
            ->when($customerId === null, fn ($q) => $q->whereNull('party_id'), fn ($q) => $q->where('party_id', $customerId))
            ->selectRaw('COALESCE(SUM(qty_party),0) AS qty, COALESCE(SUM(deposit),0) AS deposit')->first();

        return ['qty' => (int) $r->qty, 'deposit' => (int) $r->deposit];
    }

    private function resolve(array $d): array
    {
        $type = ContainerType::findOrFail($d['container_type_id']);
        $warehouse = Warehouse::where('is_active', true)->findOrFail($d['warehouse_id']);

        return [$type, $warehouse];
    }

    private function move(int $storeId, ?int $branchId, int $typeId, string $kind, array $f): ContainerMovement
    {
        $source = $f['source'] ?? null;
        unset($f['source']);

        return ContainerMovement::create([
            ...$f, 'store_id' => $storeId, 'branch_id' => $branchId, 'container_type_id' => $typeId, 'kind' => $kind,
            'source_type' => $source instanceof Model ? $source->getMorphClass() : null, 'source_id' => $source instanceof Model ? $source->getKey() : null,
        ]);
    }
}
