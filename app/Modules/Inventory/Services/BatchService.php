<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Catalogue\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Organization\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Splits saleable stock of expiry-tracked products into batches. Called by StockService inside its transaction,
 * so batches can never drift from the stock ledger by more than the "untracked" remainder (stock that existed
 * before tracking was switched on, or was added without an expiry date).
 *
 *  - Stock going out is taken oldest-expiry-first (FEFO), unless the caller names exact batches.
 *  - Sales, van loads and transfers never take expired batches, and are refused if only expired stock is left.
 *  - Stock coming in either carries its own batch details (goods receipts, opening balances) or returns to the
 *    batches it originally left (voided sales, van check-ins, transfers) by following the movement's source.
 */
class BatchService
{
    /** Movement types that must not hand out expired goods. */
    private const DISPATCH = ['sale', 'trip_issue', 'transfer_out'];

    /** Called before the balance changes, with the saleable balance as it stood. */
    public function guardDispatch(Warehouse $warehouse, Product $product, int $quantity, string $type, int $balanceBefore, array $meta): void
    {
        if ($quantity >= 0 || ! in_array($type, self::DISPATCH, true) || ($meta['allow_negative'] ?? false)) {
            return;
        }

        $expired = (int) StockBatch::withoutGlobalScopes()
            ->where(['warehouse_id' => $warehouse->id, 'product_id' => $product->id])
            ->where('expiry_date', '<', now()->toDateString())->where('quantity', '>', 0)->sum('quantity');

        if ($expired > 0 && $balanceBefore - $expired < -$quantity) {
            abort(422, sprintf(
                '%s: only %d in date. %d have expired and cannot be sold. Write them off first.',
                $product->name, max(0, $balanceBefore - $expired), $expired,
            ));
        }
    }

    public function record(StockMovement $movement, Warehouse $warehouse, Product $product, array $meta): void
    {
        $quantity = $movement->quantity;

        $allocations = $quantity < 0
            ? $this->takeOut($warehouse, $product, -$quantity, $movement->movement_type, $meta)
            : $this->bringIn($warehouse, $product, $quantity, $movement, $meta);

        foreach ($allocations as [$batchId, $qty]) {
            DB::table('stock_movement_batches')->insert([
                'stock_movement_id' => $movement->id, 'stock_batch_id' => $batchId, 'quantity' => $quantity < 0 ? -$qty : $qty,
            ]);
        }
    }

    /** @return list<array{0:int,1:int}> batch id and quantity taken */
    private function takeOut(Warehouse $warehouse, Product $product, int $needed, string $type, array $meta): array
    {
        $query = StockBatch::withoutGlobalScopes()->where(['warehouse_id' => $warehouse->id, 'product_id' => $product->id])
            ->where('quantity', '>', 0)->lockForUpdate();

        $named = collect($meta['batches'] ?? [])->pluck('batch_id')->filter()->all();

        if ($named) {
            $query->whereIn('id', $named)->orderBy('expiry_date')->orderBy('id');
        } else {
            if (in_array($type, self::DISPATCH, true)) {
                $query->where('expiry_date', '>=', now()->toDateString());
            }
            $query->orderBy('expiry_date')->orderBy('id');
        }

        $out = [];

        foreach ($query->get() as $batch) {
            if ($needed <= 0) {
                break;
            }

            $take = min($needed, $batch->quantity);
            $batch->decrement('quantity', $take);
            $out[] = [$batch->id, $take];
            $needed -= $take;
        }

        return $out;
    }

    private function bringIn(Warehouse $warehouse, Product $product, int $quantity, StockMovement $movement, array $meta): array
    {
        $plan = [];
        $left = $quantity;

        foreach ($meta['batches'] ?? [] as $b) {
            if (empty($b['expiry_date']) || $left <= 0) {
                continue;
            }

            $take = min($left, (int) $b['quantity']);
            $plan[] = [(string) ($b['batch_no'] ?? ''), $b['expiry_date'], $take];
            $left -= $take;
        }

        // No details given: follow the source document back to the batches this stock originally left.
        if (! $plan && $movement->source_type && $movement->source_id) {
            foreach ($this->stillOut($movement, $product->id) as $row) {
                if ($left <= 0) {
                    break;
                }

                $take = min($left, (int) $row->net);
                $plan[] = [$row->batch_no, $row->expiry_date, $take];
                $left -= $take;
            }
        }

        $out = [];

        foreach ($plan as [$batchNo, $expiry, $qty]) {
            $keys = ['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'batch_no' => $batchNo, 'expiry_date' => $expiry];

            DB::table('stock_batches')->insertOrIgnore([
                ...$keys, 'store_id' => $warehouse->store_id, 'branch_id' => $warehouse->branch_id,
                'quantity' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $batch = StockBatch::withoutGlobalScopes()->where($keys)->lockForUpdate()->firstOrFail();
            $batch->increment('quantity', $qty);
            $out[] = [$batch->id, $qty];
        }

        return $out;
    }

    /** Batches that left under this movement's source document and have not come back yet. */
    private function stillOut(StockMovement $movement, int $productId)
    {
        return DB::table('stock_movement_batches as mb')
            ->join('stock_movements as m', 'm.id', '=', 'mb.stock_movement_id')
            ->join('stock_batches as b', 'b.id', '=', 'mb.stock_batch_id')
            ->where('m.source_type', $movement->source_type)->where('m.source_id', $movement->source_id)
            ->where('m.product_id', $productId)->where('m.id', '!=', $movement->id)
            ->groupBy('b.batch_no', 'b.expiry_date')
            ->selectRaw('b.batch_no, b.expiry_date, -SUM(mb.quantity) as net')
            ->havingRaw('-SUM(mb.quantity) > 0')
            ->orderBy('b.expiry_date')->get();
    }
}
