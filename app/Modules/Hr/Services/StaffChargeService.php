<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\Ledger;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\StaffCharge;
use App\Modules\Inventory\Services\DocumentNumber;
use Illuminate\Support\Facades\DB;

/**
 * A staff charge is money an employee owes the business, typically for a product they damaged. Once approved it sits on
 * Staff Receivables and payroll recovers it in instalments. The matching loss was already booked when the stock was
 * written off, so approval credits that loss back (or Other Income when no product is involved).
 */
class StaffChargeService
{
    public function __construct(private Ledger $ledger, private DocumentNumber $numbers)
    {
    }

    public function create(Employee $employee, array $data, User $actor): StaffCharge
    {
        return DB::transaction(function () use ($employee, $data, $actor) {
            $amount = isset($data['quantity'], $data['unit_amount']) ? $data['quantity'] * $data['unit_amount'] : (int) $data['amount'];

            abort_if($amount < 1, 422, 'The amount to recover must be more than zero.');

            return StaffCharge::create([
                'store_id' => $employee->store_id, 'branch_id' => $employee->branch_id, 'employee_id' => $employee->id,
                'number' => $this->numbers->next($employee->store_id, 'STC'),
                'reason' => $data['reason'], 'description' => $data['description'],
                'product_id' => $data['product_id'] ?? null, 'quantity' => $data['quantity'] ?? null, 'unit_amount' => $data['unit_amount'] ?? null,
                'amount' => $amount, 'installments' => $data['installments'] ?? 1, 'incident_on' => $data['incident_on'] ?? now()->toDateString(),
                'status' => 'pending', 'created_by' => $actor->id,
            ]);
        });
    }

    public function approve(int $id, User $actor): StaffCharge
    {
        return DB::transaction(function () use ($id, $actor) {
            $charge = StaffCharge::lockForUpdate()->findOrFail($id);
            abort_unless($charge->status === 'pending', 422, 'This charge has already been decided.');
            abort_if($charge->created_by === $actor->id && ! $actor->can('payroll.pay'), 403, 'Someone else must approve a charge you raised.');

            $employee = Employee::withoutGlobalScopes()->findOrFail($charge->employee_id);

            $this->ledger->post($charge->store_id, [
                'date' => now()->toDateString(), 'memo' => "Staff charge {$charge->number}: {$employee->name} - {$charge->description}", 'branch_id' => $charge->branch_id,
                'source' => $charge, 'posting_key' => "staff-charge:{$charge->id}", 'user_id' => $actor->id,
                'lines' => [['account' => 'staff_receivable', 'debit' => $charge->amount], ['account' => $this->offset($charge), 'credit' => $charge->amount]],
            ]);

            $charge->update(['status' => 'approved', 'decided_by' => $actor->id, 'decided_at' => now()]);

            return $charge;
        });
    }

    public function reject(int $id, string $note, User $actor): StaffCharge
    {
        $charge = StaffCharge::findOrFail($id);
        abort_unless($charge->status === 'pending', 422, 'This charge has already been decided.');
        $charge->update(['status' => 'rejected', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note]);

        return $charge;
    }

    /** Forgive whatever is still owed. What was already recovered stays recovered. */
    public function waive(int $id, string $note, User $actor): StaffCharge
    {
        return DB::transaction(function () use ($id, $note, $actor) {
            $charge = StaffCharge::lockForUpdate()->findOrFail($id);
            abort_unless($charge->status === 'approved', 422, 'Only a charge that is being recovered can be waived.');

            $left = $charge->outstanding();

            if ($left > 0) {
                $this->ledger->post($charge->store_id, [
                    'date' => now()->toDateString(), 'memo' => "Staff charge {$charge->number} waived: {$note}", 'branch_id' => $charge->branch_id,
                    'source' => $charge, 'posting_key' => "staff-charge-waive:{$charge->id}", 'user_id' => $actor->id,
                    'lines' => [['account' => $this->offset($charge), 'debit' => $left], ['account' => 'staff_receivable', 'credit' => $left]],
                ]);
            }

            $charge->update(['status' => 'waived', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note]);

            return $charge;
        });
    }

    private function offset(StaffCharge $charge): string
    {
        return $charge->product_id ? 'inventory_adjustment' : 'other_income';
    }
}
