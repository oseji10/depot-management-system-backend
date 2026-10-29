<?php

namespace App\Modules\Procurement\Services;

use App\Modules\Procurement\Models\GoodsReceiptLine;
use App\Modules\Procurement\Models\PurchaseOrder;
use Illuminate\Support\Collection;

/**
 * An order line counts as received only for ACCEPTED goods from POSTED receipts.
 * Damaged and rejected goods stay outstanding: the supplier still owes good stock.
 */
class PurchaseOrderProgress
{
    /** @return Collection<int, int> order line id => accepted base units */
    public function received(PurchaseOrder $po): Collection
    {
        return GoodsReceiptLine::withoutGlobalScopes()
            ->join('goods_receipts', 'goods_receipts.id', '=', 'goods_receipt_lines.goods_receipt_id')
            ->where('goods_receipts.status', 'posted')
            ->whereIn('goods_receipt_lines.purchase_order_line_id', $po->lines()->pluck('id'))
            ->groupBy('goods_receipt_lines.purchase_order_line_id')
            ->selectRaw('goods_receipt_lines.purchase_order_line_id AS line_id, SUM(goods_receipt_lines.accepted * goods_receipt_lines.factor) AS qty')
            ->pluck('qty', 'line_id')
            ->map(fn ($q) => (int) $q);
    }

    /** Re-derive the order status after a receipt is posted or reversed. */
    public function refresh(?int $purchaseOrderId): void
    {
        if ($purchaseOrderId === null) {
            return;
        }

        $po = PurchaseOrder::withoutGlobalScopes()->with('lines')->lockForUpdate()->find($purchaseOrderId);

        if (! $po || ! in_array($po->status, ['ordered', 'partially_received', 'received'], true)) {
            return;
        }

        $received = $this->received($po);

        $complete = $po->lines->every(fn ($l) => ($received[$l->id] ?? 0) >= $l->quantity * $l->factor);
        $any = $received->sum() > 0;

        $po->update(['status' => $complete ? 'received' : ($any ? 'partially_received' : 'ordered')]);
    }
}
