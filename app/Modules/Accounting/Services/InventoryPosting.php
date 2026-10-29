<?php

namespace App\Modules\Accounting\Services;

use App\Models\User;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Support\StockStatus;
use App\Modules\Organization\Models\Warehouse;
use App\Modules\Procurement\Models\GoodsReceipt;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

/**
 * Turns stock events into journal entries and keeps the weighted-average cost records in step.
 * Only SALEABLE stock carries value; damaged and expired stock is valued at zero until it is recovered or sold off.
 *
 * Every method runs inside the caller's transaction and posts with a posting_key, so a retry cannot post twice.
 */
class InventoryPosting
{
    public function __construct(private Ledger $ledger, private InventoryCosting $costing)
    {
    }

    /** Dr Inventory / Cr Goods Received Not Invoiced, at the receipt's accepted quantity and cost. */
    public function receipt(GoodsReceipt $receipt, User $actor): void
    {
        $total = 0;

        foreach ($receipt->lines as $line) {
            $value = Money::kobo($line->accepted * (float) $line->unit_cost);
            $this->costing->add($receipt->store_id, $line->product_id, $line->accepted * $line->factor, $value);
            $total += $value;
        }

        $this->journal($receipt->store_id, $receipt->branch_id, "Goods receipt {$receipt->number}", "grn:{$receipt->id}", $receipt, $actor, [
            ['account' => 'inventory', 'debit' => $total],
            ['account' => 'grni', 'credit' => $total],
        ]);
    }

    /** Undoes the receipt's journal and takes the same quantity and value back out of the cost records. */
    public function reverseReceipt(GoodsReceipt $receipt, User $actor, string $reason): void
    {
        $original = $this->entryFor($receipt->store_id, "grn:{$receipt->id}");

        foreach ($receipt->lines as $line) {
            $value = Money::kobo($line->accepted * (float) $line->unit_cost);
            $this->costing->add($receipt->store_id, $line->product_id, -$line->accepted * $line->factor, -$value);
        }

        if ($original) {
            $this->ledger->reverse($original->id, $actor, $reason);
        }
    }

    /** Approved adjustments and reclassifications. */
    public function adjustment(StockAdjustment $adjustment, User $actor): void
    {
        $storeId = $adjustment->store_id;
        $counter = $adjustment->reason === 'opening_balance' ? 'opening_balance_equity' : 'inventory_adjustment';
        $in = 0;
        $out = 0;

        foreach ($adjustment->lines as $line) {
            $delta = $this->saleableDelta($adjustment->kind, $line->status, $line->to_status, $line->quantity);

            if ($delta > 0) {
                $unitCost = $line->unit_cost !== null ? (float) $line->unit_cost : null;

                if ($unitCost === null && ! $this->costing->hasCost($storeId, $line->product_id)) {
                    throw ValidationException::withMessages(['lines' => 'Product '.$line->product_id.' has no known cost. Enter a cost per unit on the line.']);
                }

                $value = $unitCost !== null ? Money::kobo($delta * $unitCost) : $this->costing->valueOf($storeId, $line->product_id, $delta);
                $this->costing->add($storeId, $line->product_id, $delta, $value);
                $in += $value;
            } elseif ($delta < 0) {
                $out += $this->costing->issue($storeId, $line->product_id, -$delta);
            }
        }

        $this->journal($storeId, $adjustment->branch_id, "Stock adjustment {$adjustment->number}", "adj:{$adjustment->id}", $adjustment, $actor, [
            ['account' => 'inventory', 'debit' => $in],
            ['account' => $counter, 'credit' => $in],
            ['account' => 'inventory_adjustment', 'debit' => $out],
            ['account' => 'inventory', 'credit' => $out],
        ]);
    }

    /**
     * Count variances. Gains with no known cost enter at zero value rather than blocking the count.
     *
     * @param  array<int, array{product_id:int, status:string, variance:int}>  $variances
     */
    public function countVariances(StockCount $count, array $variances, User $actor): void
    {
        $storeId = $count->store_id;
        $in = 0;
        $out = 0;

        foreach ($variances as $v) {
            if ($v['status'] !== StockStatus::SALEABLE || $v['variance'] === 0) {
                continue;
            }

            if ($v['variance'] > 0) {
                $value = $this->costing->valueOf($storeId, $v['product_id'], $v['variance']);
                $this->costing->add($storeId, $v['product_id'], $v['variance'], $value);
                $in += $value;
            } else {
                $out += $this->costing->issue($storeId, $v['product_id'], -$v['variance']);
            }
        }

        $this->journal($storeId, $count->branch_id, "Stock count {$count->number}", "cnt:{$count->id}", $count, $actor, [
            ['account' => 'inventory', 'debit' => $in],
            ['account' => 'inventory_adjustment', 'credit' => $in],
            ['account' => 'inventory_adjustment', 'debit' => $out],
            ['account' => 'inventory', 'credit' => $out],
        ]);
    }

    /** Branch-to-branch transfers move value between branches. Costs are store-wide, so only the branch tags change. */
    public function transfer(StockTransfer $transfer, Warehouse $from, Warehouse $to, User $actor): void
    {
        if ($from->branch_id === $to->branch_id) {
            return;
        }

        $value = 0;

        foreach ($transfer->lines as $line) {
            $value += $this->costing->valueOf($transfer->store_id, $line->product_id, $line->quantity);
        }

        $this->journal($transfer->store_id, null, "Stock transfer {$transfer->number}", "trf:{$transfer->id}", $transfer, $actor, [
            ['account' => 'inventory', 'debit' => $value, 'branch_id' => $to->branch_id],
            ['account' => 'inventory', 'credit' => $value, 'branch_id' => $from->branch_id],
        ]);
    }

    /**
     * Stock that came back from the road broken, or never came back, leaves the books at cost.
     * Dr Inventory adjustment (loss) / Cr Inventory. $units is [product_id => base units].
     */
    public function tripLoss(int $storeId, ?int $branchId, string $memo, string $key, $source, array $units, User $actor): void
    {
        $out = 0;

        foreach ($units as $productId => $qty) {
            if ($qty > 0) {
                $out += $this->costing->issue($storeId, (int) $productId, (int) $qty);
            }
        }

        $this->journal($storeId, $branchId, $memo, $key, $source, $actor, [
            ['account' => 'inventory_adjustment', 'debit' => $out],
            ['account' => 'inventory', 'credit' => $out],
        ]);
    }

    /** Net change in saleable units that a line causes. */
    private function saleableDelta(string $kind, string $status, ?string $toStatus, int $quantity): int
    {
        if ($kind === 'reclassify') {
            return ($status === StockStatus::SALEABLE ? -$quantity : 0) + ($toStatus === StockStatus::SALEABLE ? $quantity : 0);
        }

        return $status === StockStatus::SALEABLE ? $quantity : 0;
    }

    private function journal(int $storeId, ?int $branchId, string $memo, string $key, $source, User $actor, array $lines): void
    {
        if (array_sum(array_column($lines, 'debit')) === 0) {
            return; // zero-value movement: nothing to book
        }

        $this->ledger->post($storeId, [
            'date' => now()->toDateString(), 'memo' => $memo, 'branch_id' => $branchId, 'source' => $source,
            'posting_key' => $key, 'user_id' => $actor->id, 'lines' => $lines,
        ]);
    }

    private function entryFor(int $storeId, string $key): ?object
    {
        return \App\Modules\Accounting\Models\JournalEntry::withoutGlobalScopes()
            ->where('store_id', $storeId)->where('posting_key', $key)->first();
    }
}
