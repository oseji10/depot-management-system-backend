<?php

namespace App\Modules\Rebates\Services;

use App\Models\User;
use App\Modules\Accounting\Services\Ledger;
use App\Modules\Inventory\Services\DocumentNumber;
use App\Modules\Inventory\Support\Approvals;
use App\Modules\Rebates\Models\RebateCalculation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Lifecycle: draft -> approved (books the accrual) -> settled (books the receipt). A draft can be recalculated or voided;
 * an approved one can be voided only by reversing its accrual; a settled one is final.
 */
class RebateService
{
    public function __construct(private RebateCalculator $calculator, private DocumentNumber $numbers, private Ledger $ledger)
    {
    }

    public function calculate(int $storeId, CarbonImmutable $month, User $actor, ?int $clusterId = null, ?string $overrideReason = null): RebateCalculation
    {
        abort_if($clusterId !== null && blank($overrideReason), 422, 'Say why the cluster is being overridden.');

        return DB::transaction(function () use ($storeId, $month, $actor, $clusterId, $overrideReason) {
            $month = $month->startOfMonth();

            $existing = RebateCalculation::withoutGlobalScopes()->where('store_id', $storeId)
                ->where('period_start', $month->toDateString())->where('active', true)->lockForUpdate()->first();

            if ($existing) {
                abort_unless($existing->status === 'draft', 422,
                    "{$month->format('F Y')} already has an {$existing->status} calculation ({$existing->number}). Void it first to calculate again.");

                $existing->delete(); // draft only; lines and sources go with it
            }

            $r = $this->calculator->compute($storeId, $month, $clusterId);

            $calc = RebateCalculation::create([
                'store_id' => $storeId, 'rebate_agreement_id' => $r['agreement']->id,
                'number' => $this->numbers->next($storeId, 'REB'),
                'period_start' => $month->toDateString(), 'period_end' => $month->endOfMonth()->toDateString(),
                'status' => 'draft', 'active' => true,
                'method' => $r['agreement']->method, 'accounting_treatment' => $r['agreement']->accounting_treatment,
                'cluster_name' => $r['cluster']->name, 'cluster_volume' => $r['cluster_volume'],
                'cluster_from' => $r['cluster_from']->toDateString(), 'cluster_to' => $r['cluster_to']->toDateString(),
                'cluster_overridden' => $r['overridden'], 'override_reason' => $r['overridden'] ? $overrideReason : null,
                'total_eligible_value' => $r['total_value'], 'total_rebate' => $r['total_rebate'],
                'warnings' => $r['warnings'] ?: null, 'created_by' => $actor->id,
            ]);

            foreach ($r['lines'] as $line) {
                $calc->lines()->create([...$line, 'store_id' => $storeId]);
            }

            foreach ($r['sources'] as $s) {
                $calc->sources()->create([
                    'store_id' => $storeId, 'goods_receipt_line_id' => $s->line_id, 'product_id' => $s->product_id,
                    'rebate_category_id' => $s->rebate_category_id, 'cases' => $s->cases, 'value' => $s->value,
                    'cases_assumed' => $s->cases_assumed,
                ]);
            }

            return $calc;
        });
    }

    /** Books Dr Rebate Receivable / Cr Rebate Income (or Cost of Sales, per the agreement). */
    public function approve(int $id, User $actor, ?string $accrualDate = null): RebateCalculation
    {
        return DB::transaction(function () use ($id, $actor, $accrualDate) {
            $calc = RebateCalculation::lockForUpdate()->findOrFail($id);

            abort_unless($calc->status === 'draft', 422, 'Only a draft calculation can be approved.');
            Approvals::assertCanDecide($actor, $calc->created_by);

            $journalId = null;

            if ($calc->total_rebate > 0) {
                $entry = $this->ledger->post($calc->store_id, [
                    'date' => $accrualDate ?? $calc->period_end->toDateString(),
                    'memo' => "Rebate earned {$calc->period_start->format('F Y')} ({$calc->number})",
                    'source' => $calc, 'posting_key' => "rebate:{$calc->id}", 'user_id' => $actor->id,
                    'lines' => [
                        ['account' => 'rebate_receivable', 'debit' => $calc->total_rebate],
                        ['account' => $calc->accounting_treatment === 'cogs_reduction' ? 'cogs' : 'rebate_income', 'credit' => $calc->total_rebate],
                    ],
                ]);
                $journalId = $entry->id;
            }

            $calc->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(), 'accrual_journal_id' => $journalId]);

            return $calc;
        });
    }

    public function void(int $id, User $actor, string $reason): RebateCalculation
    {
        return DB::transaction(function () use ($id, $actor, $reason) {
            $calc = RebateCalculation::lockForUpdate()->findOrFail($id);

            abort_if($calc->status === 'settled', 422, 'A settled rebate cannot be voided.');
            abort_if($calc->status === 'void', 422, 'This calculation is already void.');

            if ($calc->status === 'approved') {
                abort_unless($actor->can('rebates.approve'), 403, 'Only an approver can void an approved calculation.');

                if ($calc->accrual_journal_id) {
                    $this->ledger->reverse($calc->accrual_journal_id, $actor, "Rebate {$calc->number} voided: {$reason}");
                }
            }

            // active becomes NULL, which frees the month so it can be calculated again.
            $calc->update(['status' => 'void', 'active' => null, 'voided_by' => $actor->id, 'voided_at' => now(), 'void_reason' => $reason]);

            return $calc;
        });
    }

    /** Coca-Cola paid the rebate (bank) or knocked it off what the depot owes (credit note). */
    public function settle(int $id, User $actor, string $method, string $date, ?string $reference): RebateCalculation
    {
        return DB::transaction(function () use ($id, $actor, $method, $date, $reference) {
            $calc = RebateCalculation::lockForUpdate()->findOrFail($id);

            abort_unless($calc->status === 'approved', 422, 'Only an approved calculation can be settled.');

            $journalId = null;

            if ($calc->total_rebate > 0) {
                $entry = $this->ledger->post($calc->store_id, [
                    'date' => $date, 'memo' => "Rebate received {$calc->number}".($reference ? " ({$reference})" : ''),
                    'source' => $calc, 'posting_key' => "rebate:{$calc->id}:settle", 'user_id' => $actor->id,
                    'lines' => [
                        ['account' => $method === 'credit_note' ? 'payables' : 'bank', 'debit' => $calc->total_rebate],
                        ['account' => 'rebate_receivable', 'credit' => $calc->total_rebate],
                    ],
                ]);
                $journalId = $entry->id;
            }

            $calc->update([
                'status' => 'settled', 'settlement_method' => $method, 'settled_on' => $date,
                'settlement_reference' => $reference, 'settled_by' => $actor->id, 'settlement_journal_id' => $journalId,
            ]);

            return $calc;
        });
    }
}
