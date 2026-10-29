<?php

namespace App\Modules\Fleet\Services;

use App\Models\User;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\Ledger;
use App\Modules\Expenses\Models\Expense;
use App\Modules\FieldSales\Models\Trip;
use App\Modules\Fleet\Models\Imprest;
use App\Modules\Inventory\Services\DocumentNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Imprest is cash handed to a driver to run a trip. It is booked as an advance (Dr Driver Imprest / Cr Cash or Bank), every
 * expense the driver logs on the trip is paid out of it (Dr expense / Cr Driver Imprest), and the sales they collect are
 * never touched. At check-in the leftover is handed back, or the overspend is paid back to the driver.
 */
class ImprestService
{
    public function __construct(private Ledger $ledger, private DocumentNumber $numbers)
    {
    }

    public function issue(Trip $trip, int $amount, string $method, ?string $note, User $actor): Imprest
    {
        return DB::transaction(function () use ($trip, $amount, $method, $note, $actor) {
            $trip = Trip::lockForUpdate()->findOrFail($trip->id);
            abort_unless($trip->isOpen(), 422, 'Imprest can only be issued on an open trip.');

            $imprest = Imprest::create([
                'store_id' => $trip->store_id, 'branch_id' => $trip->branch_id, 'trip_id' => $trip->id, 'driver_id' => $trip->rep_id,
                'number' => $this->numbers->next($trip->store_id, 'IMP'), 'amount' => $amount, 'method' => $method,
                'status' => 'issued', 'note' => $note, 'issued_by' => $actor->id,
            ]);

            $this->ledger->post($trip->store_id, [
                'date' => now()->toDateString(), 'memo' => "Imprest {$imprest->number} for trip {$trip->number}", 'branch_id' => $trip->branch_id,
                'source' => $imprest, 'posting_key' => "imprest:{$imprest->id}", 'user_id' => $actor->id,
                'lines' => [['account' => 'imprest', 'debit' => $amount], ['account' => $method === 'cash' ? 'cash' : 'bank', 'credit' => $amount]],
            ]);

            return $imprest;
        });
    }

    /** Only while the trip is open and the driver has not already spent the money. */
    public function void(int $id, string $reason, User $actor): Imprest
    {
        return DB::transaction(function () use ($id, $reason, $actor) {
            $imprest = Imprest::lockForUpdate()->findOrFail($id);
            $trip = Trip::lockForUpdate()->findOrFail($imprest->trip_id);

            abort_unless($imprest->status === 'issued', 422, 'This imprest is already void.');
            abort_unless($trip->isOpen(), 422, 'The trip is already closed.');

            if ($this->summary($trip)['balance'] < $imprest->amount) {
                throw ValidationException::withMessages(['imprest' => 'The driver has already spent part of this money, so it cannot be cancelled.']);
            }

            $entry = JournalEntry::withoutGlobalScopes()->where('store_id', $imprest->store_id)->where('posting_key', "imprest:{$imprest->id}")->first();

            if ($entry && $entry->reversed_at === null) {
                $this->ledger->reverse($entry->id, $actor, $reason);
            }

            $imprest->update(['status' => 'void', 'voided_at' => now(), 'void_reason' => $reason]);

            return $imprest;
        });
    }

    /** @return array{issued:int, spent:int, balance:int} balance is negative when the driver spent more than they were given */
    public function summary(Trip $trip): array
    {
        $issued = (int) Imprest::withoutGlobalScopes()->where('trip_id', $trip->id)->where('status', 'issued')->sum('amount');
        $spent = (int) Expense::withoutGlobalScopes()->where('trip_id', $trip->id)->where('method', 'imprest')->where('status', 'posted')->sum('amount');

        return ['issued' => $issued, 'spent' => $spent, 'balance' => $issued - $spent];
    }

    /**
     * Called when the trip closes. Leftover cash handed back reduces the advance; overspending is paid to the driver;
     * any leftover not handed back stays on the Driver Imprest account as money the driver owes.
     */
    public function settle(Trip $trip, ?int $returned, User $actor): void
    {
        $s = $this->summary($trip);
        $returned ??= max(0, $s['balance']);

        if ($s['balance'] >= 0 && $returned > $s['balance']) {
            throw ValidationException::withMessages(['imprest_returned' => 'The driver cannot hand back more than the unspent imprest.']);
        }

        $reimbursed = $s['balance'] < 0 ? -$s['balance'] : 0;
        $returned = $s['balance'] > 0 ? $returned : 0;

        if ($returned > 0) {
            $this->ledger->post($trip->store_id, [
                'date' => now()->toDateString(), 'memo' => "Imprest returned, trip {$trip->number}", 'branch_id' => $trip->branch_id,
                'source' => $trip, 'posting_key' => "imprest-return:{$trip->id}", 'user_id' => $actor->id,
                'lines' => [['account' => 'cash', 'debit' => $returned], ['account' => 'imprest', 'credit' => $returned]],
            ]);
        }

        if ($reimbursed > 0) {
            $this->ledger->post($trip->store_id, [
                'date' => now()->toDateString(), 'memo' => "Driver reimbursed, trip {$trip->number}", 'branch_id' => $trip->branch_id,
                'source' => $trip, 'posting_key' => "imprest-reimburse:{$trip->id}", 'user_id' => $actor->id,
                'lines' => [['account' => 'imprest', 'debit' => $reimbursed], ['account' => 'cash', 'credit' => $reimbursed]],
            ]);
        }

        $trip->update([
            'imprest_issued' => $s['issued'], 'imprest_spent' => $s['spent'], 'imprest_returned' => $returned,
            'imprest_reimbursed' => $reimbursed, 'imprest_unreturned' => max(0, $s['balance'] - $returned),
        ]);
    }
}
