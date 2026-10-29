<?php

namespace App\Modules\Accounting\Services;

use App\Models\User;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Sales\Models\PosTerminal;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SalePayment;

/**
 * Sales to the books. The sale is always booked to Trade Receivables first, and every payment then clears it, so a
 * customer's balance in the books is exactly the sum of their unpaid sales, whether they paid at the till or later.
 *
 *   sale     Dr Trade Receivables / Cr Sales (+ Cr Customer Container Deposits)     Dr Cost of Goods Sold / Cr Inventory
 *   payment  Dr Cash on Hand (cash) or Bank (transfer, POS) / Cr Trade Receivables
 *
 * Runs inside the caller's transaction. Posting keys make a retry harmless.
 */
class SalesPosting
{
    public function __construct(private Ledger $ledger, private InventoryCosting $costing)
    {
    }

    public function sale(Sale $sale, User $actor): void
    {
        $this->ledger->post($sale->store_id, [
            'date' => $sale->sold_at->toDateString(), 'memo' => "Sale {$sale->number}", 'branch_id' => $sale->branch_id,
            'source' => $sale, 'posting_key' => "sale:{$sale->id}", 'user_id' => $actor->id,
            'lines' => [
                ['account' => 'receivables', 'debit' => $sale->total + $sale->deposit_total],
                ['account' => 'sales', 'credit' => $sale->total],
                ['account' => 'container_deposits', 'credit' => $sale->deposit_total],
                ['account' => 'cogs', 'debit' => $sale->cogs_total],
                ['account' => 'inventory', 'credit' => $sale->cogs_total],
            ],
        ]);
    }

    public function payment(Sale $sale, SalePayment $payment, User $actor): void
    {
        $this->ledger->post($sale->store_id, [
            'date' => $payment->paid_at->toDateString(), 'memo' => "Payment on {$sale->number} ({$payment->method})", 'branch_id' => $sale->branch_id,
            'source' => $payment, 'posting_key' => "salepay:{$payment->id}", 'user_id' => $actor->id,
            'lines' => [
                ['account' => $this->receivingAccount($payment), 'debit' => $payment->amount],
                ['account' => 'receivables', 'credit' => $payment->amount],
            ],
        ]);
    }

    /** Cash goes to Cash on Hand; card and transfer to the Bank, unless the card machine has its own account. */
    private function receivingAccount(SalePayment $payment): int|string
    {
        if ($payment->method === 'cash') {
            return 'cash';
        }

        if ($payment->pos_terminal_id && ($id = PosTerminal::withoutGlobalScopes()->whereKey($payment->pos_terminal_id)->value('account_id'))) {
            return (int) $id;
        }

        return 'bank';
    }

    /** Reverses the sale and every payment taken against it, and puts the cost of the goods back into the cost records. */
    public function reverse(Sale $sale, User $actor, string $reason): void
    {
        foreach ($sale->payments as $payment) {
            $this->reverseEntry($sale->store_id, "salepay:{$payment->id}", $actor, $reason);
        }

        $this->reverseEntry($sale->store_id, "sale:{$sale->id}", $actor, $reason);

        foreach ($sale->lines as $line) {
            $this->costing->add($sale->store_id, $line->product_id, $line->quantity * $line->factor, $line->cost_total);
        }
    }

    private function reverseEntry(int $storeId, string $key, User $actor, string $reason): void
    {
        $entry = JournalEntry::withoutGlobalScopes()->where('store_id', $storeId)->where('posting_key', $key)->first();

        if ($entry && $entry->reversed_at === null) {
            $this->ledger->reverse($entry->id, $actor, $reason);
        }
    }
}
