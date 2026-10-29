<?php

namespace App\Modules\Inventory\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Proves stock_balances equals SUM(stock_movements). The ledger always wins. */
class StockReconciler
{
    /** @return Collection<int, array{warehouse_id:int, product_id:int, status:string, ledger:int, balance:int}> */
    public function discrepancies(?int $storeId = null): Collection
    {
        $key = fn ($r) => "{$r->warehouse_id}:{$r->product_id}:{$r->status}";

        $ledger = DB::table('stock_movements')
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->selectRaw('warehouse_id, product_id, status, SUM(quantity) AS qty')
            ->groupBy('warehouse_id', 'product_id', 'status')
            ->get()->keyBy($key);

        $balances = DB::table('stock_balances')
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->select('warehouse_id', 'product_id', 'status', 'quantity')
            ->get()->keyBy($key);

        return $ledger->keys()->merge($balances->keys())->unique()->map(function ($k) use ($ledger, $balances) {
            [$warehouse, $product, $status] = explode(':', $k);
            $l = (int) ($ledger[$k]->qty ?? 0);
            $b = (int) ($balances[$k]->quantity ?? 0);

            return $l === $b ? null : [
                'warehouse_id' => (int) $warehouse, 'product_id' => (int) $product, 'status' => $status,
                'ledger' => $l, 'balance' => $b,
            ];
        })->filter()->values();
    }

    /** Overwrite drifting balances with the ledger total. Returns how many were repaired. */
    public function repair(Collection $discrepancies): int
    {
        foreach ($discrepancies as $d) {
            $warehouse = DB::table('warehouses')->where('id', $d['warehouse_id'])->first();

            DB::table('stock_balances')->updateOrInsert(
                ['warehouse_id' => $d['warehouse_id'], 'product_id' => $d['product_id'], 'status' => $d['status']],
                ['store_id' => $warehouse->store_id, 'branch_id' => $warehouse->branch_id, 'quantity' => $d['ledger'], 'updated_at' => now()],
            );
        }

        return $discrepancies->count();
    }
}
