<?php

namespace App\Modules\Accounting\Services;

use Illuminate\Support\Facades\DB;

/**
 * Weighted-average cost of saleable stock, per product, for the whole store. Values are integer kobo.
 * Callers run inside the transaction that also moves stock, so a failure rolls both back.
 */
class InventoryCosting
{
    /** Adds (or, with negatives, removes) quantity and value together. */
    public function add(int $storeId, int $productId, int $quantity, int $valueKobo): void
    {
        $row = $this->lock($storeId, $productId);
        $qty = $row->quantity + $quantity;
        $value = $row->value + $valueKobo;

        // Costing never blocks real stock movement; it clamps and the reconciliation report shows any drift.
        if ($qty <= 0) {
            $qty = max($qty, 0);
            $value = 0;
        }

        DB::table('product_costs')->where('id', $row->id)->update(['quantity' => $qty, 'value' => max($value, 0), 'updated_at' => now()]);
    }

    /** Takes stock out at the current average cost and returns the value removed. */
    public function issue(int $storeId, int $productId, int $quantity): int
    {
        $row = $this->lock($storeId, $productId);
        $value = $this->proportion($row->value, $row->quantity, $quantity);

        $this->add($storeId, $productId, -$quantity, -$value);

        return $value;
    }

    /** What `quantity` units are worth right now, without changing anything. */
    public function valueOf(int $storeId, int $productId, int $quantity): int
    {
        $row = DB::table('product_costs')->where('store_id', $storeId)->where('product_id', $productId)->first();

        return $row ? $this->proportion($row->value, $row->quantity, $quantity) : 0;
    }

    public function hasCost(int $storeId, int $productId): bool
    {
        return DB::table('product_costs')->where('store_id', $storeId)->where('product_id', $productId)
            ->where('quantity', '>', 0)->where('value', '>', 0)->exists();
    }

    private function proportion(int $value, int $onHand, int $quantity): int
    {
        if ($onHand <= 0 || $value <= 0) {
            return 0;
        }

        return $quantity >= $onHand ? $value : (int) round($value * $quantity / $onHand);
    }

    private function lock(int $storeId, int $productId): object
    {
        DB::table('product_costs')->insertOrIgnore([
            'store_id' => $storeId, 'product_id' => $productId, 'quantity' => 0, 'value' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('product_costs')->where('store_id', $storeId)->where('product_id', $productId)->lockForUpdate()->first();
    }
}
