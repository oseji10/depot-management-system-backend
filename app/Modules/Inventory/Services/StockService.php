<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Catalogue\Models\Product;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockStatus;
use App\Modules\Organization\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The ONLY way stock changes. Every call writes one ledger row and updates the matching balance in the same
 * transaction, under a row lock, so two people can never both spend the last crate.
 *
 * Callers are responsible for authorisation (they load the warehouse through scoped queries).
 */
class StockService
{
    public function __construct(private BatchService $batches)
    {
    }

    /**
     * @param  int  $quantity  signed, in base units
     * @param  array{source?:Model, reference?:string, note?:string, idempotency_key?:string, unit_cost?:float,
     *               occurred_at?:\DateTimeInterface, user_id?:int, allow_negative?:bool,
     *               batches?:list<array>}  $meta  batches: in = [batch_no, expiry_date, quantity]; out = [batch_id] to take from exact batches
     */
    public function move(Warehouse $warehouse, int $productId, string $status, int $quantity, string $type, array $meta = []): StockMovement
    {
        if ($quantity === 0) {
            throw new InvalidArgumentException('A stock movement cannot be zero.');
        }

        if (! in_array($status, StockStatus::ALL, true)) {
            throw new InvalidArgumentException("Unknown stock status: {$status}");
        }

        return DB::transaction(function () use ($warehouse, $productId, $status, $quantity, $type, $meta) {
            // Retried or re-synced requests return the original movement instead of posting twice.
            if ($key = $meta['idempotency_key'] ?? null) {
                $existing = StockMovement::withoutGlobalScopes()
                    ->where('store_id', $warehouse->store_id)->where('idempotency_key', $key)->first();

                if ($existing) {
                    return $existing;
                }
            }

            $product = Product::withoutGlobalScopes()->where('store_id', $warehouse->store_id)->findOrFail($productId);

            $keys = ['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'status' => $status];

            DB::table('stock_balances')->insertOrIgnore([
                ...$keys,
                'store_id' => $warehouse->store_id, 'branch_id' => $warehouse->branch_id,
                'quantity' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $balance = StockBalance::withoutGlobalScopes()->where($keys)->lockForUpdate()->firstOrFail();
            $new = $balance->quantity + $quantity;

            if ($new < 0 && ! ($meta['allow_negative'] ?? false)) {
                abort(422, sprintf(
                    'Not enough %s stock for %s in %s: %d available, %d needed.',
                    $status, $product->name, $warehouse->name, $balance->quantity, -$quantity,
                ));
            }

            $tracked = $status === StockStatus::SALEABLE && $product->tracks_expiry;

            if ($tracked) {
                $this->batches->guardDispatch($warehouse, $product, $quantity, $type, (int) $balance->quantity, $meta);
            }

            $balance->update(['quantity' => $new]);

            $source = $meta['source'] ?? null;

            $movement = StockMovement::create([
                'store_id' => $warehouse->store_id,
                'branch_id' => $warehouse->branch_id,
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'status' => $status,
                'quantity' => $quantity,
                'movement_type' => $type,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'reference' => $meta['reference'] ?? null,
                'note' => $meta['note'] ?? null,
                'unit_cost' => $meta['unit_cost'] ?? null,
                'idempotency_key' => $meta['idempotency_key'] ?? null,
                'user_id' => $meta['user_id'] ?? Auth::id(),
                'occurred_at' => $meta['occurred_at'] ?? now(),
            ]);

            if ($tracked) {
                $this->batches->record($movement, $warehouse, $product, $meta);
            }

            return $movement;
        });
    }

    /** Current balance of one product in one warehouse and status (0 when it has never moved). */
    public function balance(int $warehouseId, int $productId, string $status = StockStatus::SALEABLE): int
    {
        return (int) StockBalance::withoutGlobalScopes()
            ->where(['warehouse_id' => $warehouseId, 'product_id' => $productId, 'status' => $status])
            ->value('quantity');
    }
}
