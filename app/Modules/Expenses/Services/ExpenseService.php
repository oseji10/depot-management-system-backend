<?php

namespace App\Modules\Expenses\Services;

use App\Models\User;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\Ledger;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Modules\FieldSales\Models\Trip;
use App\Modules\Inventory\Services\DocumentNumber;
use App\Modules\Organization\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Dr the category's expense account / Cr Cash on Hand, Bank, or Driver Imprest. A driver's expense is tied to their open trip and is
 * paid from the imprest they were given. It does not reduce the sales cash they hand in.
 */
class ExpenseService
{
    public function __construct(private Ledger $ledger, private DocumentNumber $numbers)
    {
    }

    /** @param array{category_id:int, amount:int, method:string, expense_date?:?string, paid_to?:?string, description?:?string, client_ref?:?string, branch_id?:?int, offline?:bool} $data */
    public function create(array $data, User $actor): Expense
    {
        return DB::transaction(function () use ($data, $actor) {
            $storeId = $actor->store_id;

            if (($ref = $data['client_ref'] ?? null) && ($existing = Expense::withoutGlobalScopes()->where('store_id', $storeId)->where('client_ref', $ref)->first())) {
                return $existing;
            }

            $category = ExpenseCategory::where('is_active', true)->find($data['category_id'])
                ?? throw ValidationException::withMessages(['category_id' => 'Choose an active expense category.']);

            $van = Warehouse::vanOf($actor->id);
            $trip = $van ? Trip::withoutGlobalScopes()->where('van_warehouse_id', $van->id)->where('status', 'open')->first() : null;
            $branchId = $van?->branch_id ?? $actor->branch_id ?? ($data['branch_id'] ?? null);

            if (! $branchId) {
                throw ValidationException::withMessages(['branch_id' => 'Choose the branch this expense belongs to.']);
            }

            if ($van && ! $trip) {
                throw ValidationException::withMessages(['trip' => 'You can log expenses while you are out on a trip.']);
            }

            // A driver on a trip spends from the imprest they were given, never from the sales they collect.
            // Anyone else cannot claim 'imprest' because there is no trip to charge it to.
            $method = $trip ? 'imprest' : $data['method'];

            if ($method === 'imprest' && ! $trip) {
                throw ValidationException::withMessages(['method' => 'Imprest can only be used for expenses on a trip.']);
            }

            $date = $data['expense_date'] ?? now()->toDateString();

            if ($date > now()->addDay()->toDateString()) {
                throw ValidationException::withMessages(['expense_date' => 'The date cannot be in the future.']);
            }

            $expense = Expense::create([
                'store_id' => $storeId, 'branch_id' => $branchId, 'number' => $this->numbers->next($storeId, 'EXP'),
                'category_id' => $category->id, 'expense_date' => $date, 'amount' => $data['amount'], 'method' => $method,
                'paid_to' => $data['paid_to'] ?? null, 'description' => $data['description'] ?? null, 'trip_id' => $trip?->id,
                'client_ref' => $ref, 'offline' => (bool) ($data['offline'] ?? false), 'status' => 'posted', 'created_by' => $actor->id,
            ]);

            $this->ledger->post($storeId, [
                'date' => $date, 'memo' => "Expense {$expense->number}: {$category->name}", 'branch_id' => $branchId,
                'source' => $expense, 'posting_key' => "expense:{$expense->id}", 'user_id' => $actor->id,
                'lines' => [
                    ['account' => $category->account_id, 'debit' => $expense->amount],
                    ['account' => match ($method) { 'cash' => 'cash', 'imprest' => 'imprest', default => 'bank' }, 'credit' => $expense->amount],
                ],
            ]);

            return $expense;
        });
    }

    public function void(int $id, string $reason, User $actor): Expense
    {
        return DB::transaction(function () use ($id, $reason, $actor) {
            $expense = Expense::lockForUpdate()->findOrFail($id);

            abort_unless($expense->status === 'posted', 422, 'This expense is already void.');
            abort_if(
                $expense->trip_id && Trip::withoutGlobalScopes()->whereKey($expense->trip_id)->where('status', 'closed')->exists(),
                422, 'This expense belongs to a trip that is already checked in.',
            );

            $entry = JournalEntry::withoutGlobalScopes()->where('store_id', $expense->store_id)->where('posting_key', "expense:{$expense->id}")->first();

            if ($entry && $entry->reversed_at === null) {
                $this->ledger->reverse($entry->id, $actor, $reason);
            }

            $expense->update(['status' => 'void', 'voided_by' => $actor->id, 'voided_at' => now(), 'void_reason' => $reason]);

            return $expense;
        });
    }
}
