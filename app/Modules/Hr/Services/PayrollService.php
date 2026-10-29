<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\Ledger;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayItem;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\PayslipLine;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\StaffCharge;
use App\Modules\Inventory\Services\DocumentNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A payroll run is one month's pay for the active staff. Draft runs can be recalculated and adjusted. Approving a run
 * books the salary expense and recovers staff charges; paying it settles the net pay from cash or bank.
 */
class PayrollService
{
    public function __construct(private Ledger $ledger, private DocumentNumber $numbers)
    {
    }

    public function create(int $storeId, string $period, ?int $branchId, ?string $note, User $actor): PayrollRun
    {
        return DB::transaction(function () use ($storeId, $period, $branchId, $note, $actor) {
            $clash = PayrollRun::where('period', $period)->where('status', '!=', 'void')
                ->where(fn ($q) => $branchId === null ? $q : $q->whereNull('branch_id')->orWhere('branch_id', $branchId))->exists();

            if ($clash) {
                throw ValidationException::withMessages(['period' => 'There is already a payroll run for that month covering these staff.']);
            }

            $run = PayrollRun::create([
                'store_id' => $storeId, 'branch_id' => $branchId, 'number' => $this->numbers->next($storeId, 'PAY'),
                'period' => $period, 'status' => 'draft', 'note' => $note, 'created_by' => $actor->id,
            ]);

            $this->populate($run);

            return $run->fresh();
        });
    }

    /** Rebuild every payslip from today's salaries, pay items and staff charges. Manual lines are kept. */
    public function recalculate(int $id): PayrollRun
    {
        return DB::transaction(function () use ($id) {
            $run = PayrollRun::lockForUpdate()->findOrFail($id);
            abort_unless($run->status === 'draft', 422, 'Only a draft run can be recalculated.');

            $this->populate($run);

            return $run->fresh();
        });
    }

    public function addLine(int $payslipId, string $kind, string $label, int $amount): Payslip
    {
        return DB::transaction(function () use ($payslipId, $kind, $label, $amount) {
            $slip = Payslip::lockForUpdate()->findOrFail($payslipId);
            $this->assertDraft($slip);

            $slip->lines()->create(['kind' => $kind, 'source' => 'manual', 'label' => $label, 'amount' => $amount]);
            $this->build($slip);
            $this->totals($slip->run);

            return $slip->fresh('lines');
        });
    }

    public function removeLine(int $lineId): void
    {
        DB::transaction(function () use ($lineId) {
            $line = PayslipLine::findOrFail($lineId);
            $slip = Payslip::lockForUpdate()->findOrFail($line->payslip_id);
            $this->assertDraft($slip);
            abort_unless($line->source === 'manual', 422, 'Only lines added by hand can be removed. Change the pay item or the staff charge instead.');

            $line->delete();
            $this->build($slip);
            $this->totals($slip->run);
        });
    }

    public function approve(int $id, User $actor): PayrollRun
    {
        return DB::transaction(function () use ($id, $actor) {
            $run = PayrollRun::lockForUpdate()->findOrFail($id);
            abort_unless($run->status === 'draft', 422, 'This run is not a draft.');

            $slips = $run->payslips()->get();
            abort_if($slips->isEmpty(), 422, 'There are no payslips in this run.');

            // Charges can have changed since the draft was made, so refresh the deductions before anything is booked.
            foreach ($slips as $slip) {
                $this->build($slip);
            }

            $this->totals($run);
            $run->refresh();

            $charges = (int) $run->payslips()->sum('charges_total');
            $others = (int) $run->payslips()->sum('other_deductions');

            $this->ledger->post($run->store_id, [
                'date' => now()->toDateString(), 'memo' => "Payroll {$run->number} for {$run->period}", 'branch_id' => $run->branch_id,
                'source' => $run, 'posting_key' => "payroll:{$run->id}", 'user_id' => $actor->id,
                'lines' => array_values(array_filter([
                    ['account' => 'salaries_expense', 'debit' => $run->total_gross],
                    $charges > 0 ? ['account' => 'staff_receivable', 'credit' => $charges] : null,
                    $others > 0 ? ['account' => 'payroll_deductions', 'credit' => $others] : null,
                    $run->total_net > 0 ? ['account' => 'payroll_payable', 'credit' => $run->total_net] : null,
                ])),
            ]);

            foreach (PayslipLine::whereIn('payslip_id', $slips->pluck('id'))->where('source', 'staff_charge')->get() as $line) {
                $charge = StaffCharge::withoutGlobalScopes()->lockForUpdate()->find($line->source_id);

                if ($charge) {
                    $recovered = $charge->recovered + $line->amount;
                    $charge->update(['recovered' => $recovered, 'status' => $recovered >= $charge->amount ? 'cleared' : 'approved']);
                }
            }

            $run->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()]);

            return $run;
        });
    }

    public function pay(int $id, string $method, User $actor): PayrollRun
    {
        return DB::transaction(function () use ($id, $method, $actor) {
            $run = PayrollRun::lockForUpdate()->findOrFail($id);
            abort_unless($run->status === 'approved', 422, 'Approve the run before paying it.');

            if ($run->total_net > 0) {
                $this->ledger->post($run->store_id, [
                    'date' => now()->toDateString(), 'memo' => "Salaries paid, payroll {$run->number}", 'branch_id' => $run->branch_id,
                    'source' => $run, 'posting_key' => "payroll-pay:{$run->id}", 'user_id' => $actor->id,
                    'lines' => [['account' => 'payroll_payable', 'debit' => $run->total_net], ['account' => $method === 'cash' ? 'cash' : 'bank', 'credit' => $run->total_net]],
                ]);
            }

            $run->update(['status' => 'paid', 'pay_method' => $method, 'paid_at' => now(), 'paid_by' => $actor->id]);

            return $run;
        });
    }

    /** A draft is simply deleted. An approved run that has not been paid is reversed and its charge recoveries put back. */
    public function void(int $id, string $reason, User $actor): PayrollRun
    {
        return DB::transaction(function () use ($id, $reason, $actor) {
            $run = PayrollRun::lockForUpdate()->findOrFail($id);
            abort_unless(in_array($run->status, ['draft', 'approved'], true), 422, 'A paid run cannot be voided. Post a correcting journal instead.');

            if ($run->status === 'approved') {
                $entry = JournalEntry::withoutGlobalScopes()->where('store_id', $run->store_id)->where('posting_key', "payroll:{$run->id}")->first();

                if ($entry && $entry->reversed_at === null) {
                    $this->ledger->reverse($entry->id, $actor, $reason);
                }

                $lines = PayslipLine::whereIn('payslip_id', $run->payslips()->pluck('id'))->where('source', 'staff_charge')->get();

                foreach ($lines as $line) {
                    $charge = StaffCharge::withoutGlobalScopes()->lockForUpdate()->find($line->source_id);
                    $charge?->update(['recovered' => max(0, $charge->recovered - $line->amount), 'status' => 'approved']);
                }
            }

            $run->update(['status' => 'void', 'void_reason' => $reason]);

            return $run;
        });
    }

    private function assertDraft(Payslip $slip): void
    {
        abort_unless(PayrollRun::findOrFail($slip->payroll_run_id)->status === 'draft', 422, 'This payslip belongs to a run that is no longer a draft.');
    }

    /** Make a payslip for every active employee in scope, replacing the automatic lines of any that already exist. */
    private function populate(PayrollRun $run): void
    {
        [$year, $month] = array_map('intval', explode('-', $run->period));
        $monthEnd = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();

        $employees = Employee::withoutGlobalScopes()->where('store_id', $run->store_id)->where('status', 'active')
            ->when($run->branch_id, fn ($q, $b) => $q->where('branch_id', $b))
            ->where(fn ($q) => $q->whereNull('hired_on')->orWhere('hired_on', '<=', $monthEnd))->orderBy('name')->get();

        $keep = [];

        foreach ($employees as $employee) {
            $slip = Payslip::firstOrCreate(['payroll_run_id' => $run->id, 'employee_id' => $employee->id], ['store_id' => $run->store_id]);
            $this->build($slip);
            $keep[] = $slip->id;
        }

        Payslip::where('payroll_run_id', $run->id)->whereNotIn('id', $keep)->delete();
        $this->totals($run);
    }

    /** Recompute one payslip: basic, allowances, deductions, then staff charges out of whatever pay is left. */
    private function build(Payslip $slip): void
    {
        $employee = Employee::withoutGlobalScopes()->with('payItems')->findOrFail($slip->employee_id);
        $manual = $slip->lines()->where('source', 'manual')->get();

        $slip->lines()->where('source', '!=', 'manual')->delete();

        $basic = $employee->basic_salary;
        $overrides = $employee->payItems->pluck('value', 'pay_item_id');
        $assigned = $employee->payItems->pluck('pay_item_id');

        $items = PayItem::withoutGlobalScopes()->where('store_id', $slip->store_id)->where('is_active', true)
            ->where(fn ($q) => $q->where('apply_to_all', true)->orWhereIn('id', $assigned))->orderBy('kind')->orderBy('name')->get();

        $lines = [['earning', 'basic', null, 'Basic salary', $basic]];

        foreach ($items->where('kind', 'earning') as $item) {
            $lines[] = ['earning', 'pay_item', $item->id, $item->name, $item->amountFor($basic, $overrides[$item->id] ?? null)];
        }

        $gross = array_sum(array_column($lines, 4)) + (int) $manual->where('kind', 'earning')->sum('amount');
        $left = $gross - (int) $manual->where('kind', 'deduction')->sum('amount');

        foreach ($items->where('kind', 'deduction') as $item) {
            $amount = min($left, $item->amountFor($basic, $overrides[$item->id] ?? null));
            $lines[] = ['deduction', 'pay_item', $item->id, $item->name, max(0, $amount)];
            $left -= max(0, $amount);
        }

        $charges = StaffCharge::withoutGlobalScopes()->where('employee_id', $employee->id)->where('status', 'approved')->orderBy('id')->get();

        foreach ($charges as $charge) {
            $take = min($charge->monthlyDeduction(), max(0, $left));

            if ($take > 0) {
                $lines[] = ['deduction', 'staff_charge', $charge->id, "{$charge->number} - {$charge->description}", $take];
                $left -= $take;
            }
        }

        foreach ($lines as [$kind, $source, $sourceId, $label, $amount]) {
            if ($amount > 0 || $source === 'basic') {
                $slip->lines()->create(['kind' => $kind, 'source' => $source, 'source_id' => $sourceId, 'label' => $label, 'amount' => $amount]);
            }
        }

        $all = $slip->lines()->get();
        $deductions = (int) $all->where('kind', 'deduction')->sum('amount');
        $chargesTotal = (int) $all->where('source', 'staff_charge')->sum('amount');

        $earned = (int) $all->where('kind', 'earning')->sum('amount');

        if ($deductions > $earned) {
            throw ValidationException::withMessages(['amount' => "{$employee->name}'s deductions would be more than their pay."]);
        }

        $slip->update([
            'basic' => $basic, 'gross' => $earned,
            'charges_total' => $chargesTotal, 'other_deductions' => $deductions - $chargesTotal,
            'net' => $earned - $deductions,
        ]);
    }

    private function totals(PayrollRun $run): void
    {
        $slips = Payslip::where('payroll_run_id', $run->id);

        $run->update([
            'total_gross' => (int) (clone $slips)->sum('gross'),
            'total_deductions' => (int) (clone $slips)->sum('charges_total') + (int) (clone $slips)->sum('other_deductions'),
            'total_net' => (int) (clone $slips)->sum('net'),
        ]);
    }
}
