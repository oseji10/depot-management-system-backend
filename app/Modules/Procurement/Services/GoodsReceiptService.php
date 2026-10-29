<?php

namespace App\Modules\Procurement\Services;

use App\Models\User;
use App\Modules\Accounting\Services\InventoryPosting;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Inventory\Support\StockStatus;
use App\Modules\Organization\Models\Warehouse;
use App\Modules\Procurement\Models\GoodsReceipt;
use Illuminate\Support\Facades\DB;

/**
 * Posting a receipt is the moment stock (and, later, the journal entry and rebate eligibility) is created.
 * Everything happens in one transaction: either the whole receipt posts, or nothing does.
 */
class GoodsReceiptService
{
    public function __construct(private StockService $stock, private PurchaseOrderProgress $progress, private InventoryPosting $posting)
    {
    }

    public function post(int $id, User $actor): GoodsReceipt
    {
        return DB::transaction(function () use ($id, $actor) {
            $receipt = GoodsReceipt::with('lines')->lockForUpdate()->findOrFail($id);

            abort_unless($receipt->status === 'draft', 422, 'Only a draft receipt can be posted.');
            abort_if($receipt->lines->isEmpty(), 422, 'This receipt has no lines.');

            $warehouse = Warehouse::findOrFail($receipt->warehouse_id);

            foreach ($receipt->lines as $line) {
                $costPerBase = round((float) $line->unit_cost / $line->factor, 4);
                $meta = fn (string $part) => [
                    'source' => $receipt, 'reference' => $receipt->number, 'unit_cost' => $costPerBase,
                    'note' => $line->reason, 'user_id' => $actor->id, 'idempotency_key' => "grn:{$receipt->id}:{$line->id}:{$part}",
                ];

                if ($line->accepted > 0) {
                    $batches = $line->expiry_date ? [['batch_no' => $line->batch_no, 'expiry_date' => $line->expiry_date->toDateString(), 'quantity' => $line->accepted * $line->factor]] : [];
                    $this->stock->move($warehouse, $line->product_id, StockStatus::SALEABLE, $line->accepted * $line->factor, 'receipt', [...$meta('ok'), 'batches' => $batches]);
                }

                if ($line->damaged > 0) {
                    $this->stock->move($warehouse, $line->product_id, StockStatus::DAMAGED, $line->damaged * $line->factor, 'receipt', $meta('dmg'));
                }
            }

            $this->posting->receipt($receipt, $actor);

            $receipt->update(['status' => 'posted', 'posted_by' => $actor->id, 'posted_at' => now()]);
            $this->progress->refresh($receipt->purchase_order_id);

            return $receipt;
        });
    }

    /**
     * Posted receipts are never edited. A reversal books the opposite stock movements and keeps both on record.
     * It fails, harmlessly, if the goods have already been sold or moved on.
     */
    public function reverse(int $id, User $actor, string $reason): GoodsReceipt
    {
        return DB::transaction(function () use ($id, $actor, $reason) {
            $receipt = GoodsReceipt::with('lines')->lockForUpdate()->findOrFail($id);

            abort_unless($receipt->status === 'posted', 422, 'Only a posted receipt can be reversed.');

            $warehouse = Warehouse::findOrFail($receipt->warehouse_id);

            foreach ($receipt->lines as $line) {
                $meta = fn (string $part) => [
                    'source' => $receipt, 'reference' => $receipt->number, 'note' => "Reversal: {$reason}",
                    'user_id' => $actor->id, 'idempotency_key' => "grn:{$receipt->id}:{$line->id}:{$part}:rev",
                ];

                if ($line->accepted > 0) {
                    // Take back from the exact batches this line created, not just the oldest.
                    $batches = DB::table('stock_movement_batches as mb')
                        ->join('stock_movements as m', 'm.id', '=', 'mb.stock_movement_id')
                        ->where('m.store_id', $receipt->store_id)->where('m.idempotency_key', "grn:{$receipt->id}:{$line->id}:ok")
                        ->get(['mb.stock_batch_id as batch_id'])->map(fn ($r) => ['batch_id' => $r->batch_id])->all();

                    $this->stock->move($warehouse, $line->product_id, StockStatus::SALEABLE, -$line->accepted * $line->factor, 'receipt_reversal', [...$meta('ok'), 'batches' => $batches]);
                }

                if ($line->damaged > 0) {
                    $this->stock->move($warehouse, $line->product_id, StockStatus::DAMAGED, -$line->damaged * $line->factor, 'receipt_reversal', $meta('dmg'));
                }
            }

            $this->posting->reverseReceipt($receipt, $actor, $reason);

            $receipt->update(['status' => 'reversed', 'reversed_by' => $actor->id, 'reversed_at' => now(), 'reversal_reason' => $reason]);
            $this->progress->refresh($receipt->purchase_order_id);

            return $receipt;
        });
    }
}
