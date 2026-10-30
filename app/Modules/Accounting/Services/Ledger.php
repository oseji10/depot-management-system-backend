<?php

namespace App\Modules\Accounting\Services;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Inventory\Services\DocumentNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only way journal entries are created. Guarantees: debits equal credits, the period is open, and a posting_key
 * can never post twice. Amounts are integer kobo.
 *
 * Line format: ['account' => 'inventory' (role) | 12 (id), 'debit' => 5000, 'credit' => 0, 'branch_id' => ?, 'memo' => ?]
 */
class Ledger
{
    public function __construct(private DocumentNumber $numbers, private FiscalPeriods $periods)
    {
    }

    /**
     * @param  array{date:string, memo:string, lines:array, branch_id?:?int, source?:Model, posting_key?:string, user_id:int}  $spec
     */
    public function post(int $storeId, array $spec): JournalEntry
    {
        return $this->record($storeId, $spec, true);
    }

    public function draft(int $storeId, array $spec): JournalEntry
    {
        return $this->record($storeId, $spec, false);
    }

    private function record(int $storeId, array $spec, bool $post): JournalEntry
    {
        return DB::transaction(function () use ($storeId, $spec, $post) {
            $key = $spec['posting_key'] ?? null;

            if ($key && ($existing = JournalEntry::withoutGlobalScopes()->where('store_id', $storeId)->where('posting_key', $key)->first())) {
                return $existing;
            }

            $lines = $this->normalise($storeId, $spec['lines']);
            $total = $this->assertBalanced($lines);

            if ($post) {
                $this->periods->assertOpen($storeId, $spec['date']);
            }

            $source = $spec['source'] ?? null;

            $entry = JournalEntry::create([
                'store_id' => $storeId, 'branch_id' => $spec['branch_id'] ?? null,
                'number' => $this->numbers->next($storeId, 'JE'), 'status' => 'draft',
                'entry_date' => $spec['date'], 'memo' => $spec['memo'], 'total' => $total,
                'source_type' => $source?->getMorphClass(), 'source_id' => $source?->getKey(),
                'posting_key' => $key, 'reversal_of_id' => $spec['reversal_of_id'] ?? null,
                'created_by' => $spec['user_id'],
            ]);

            foreach ($lines as $line) {
                $entry->lines()->create([...$line, 'store_id' => $storeId, 'branch_id' => $line['branch_id'] ?? $spec['branch_id'] ?? null]);
            }

            if ($post) {
                $entry->update(['status' => 'posted', 'posted_by' => $spec['user_id'], 'posted_at' => now()]);
            }

            return $entry;
        });
    }

    public function postDraft(int $id, User $actor): JournalEntry
    {
        return DB::transaction(function () use ($id, $actor) {
            $entry = JournalEntry::with('lines')->lockForUpdate()->findOrFail($id);

            abort_unless($entry->status === 'draft', 422, 'This entry is already posted.');

            $lines = $entry->lines->map(fn ($l) => ['debit' => $l->debit, 'credit' => $l->credit])->all();
            $this->assertBalanced($lines);
            $this->periods->assertOpen($entry->store_id, $entry->entry_date->toDateString());

            $entry->update(['status' => 'posted', 'posted_by' => $actor->id, 'posted_at' => now()]);

            return $entry;
        });
    }

    /** Books the exact opposite entry and marks the original as reversed. Both stay on record. */
    public function reverse(int $id, User $actor, string $reason, ?string $date = null): JournalEntry
    {
        return DB::transaction(function () use ($id, $actor, $reason, $date) {
            $original = JournalEntry::with('lines')->lockForUpdate()->findOrFail($id);

            abort_unless($original->status === 'posted', 422, 'Only a posted entry can be reversed.');
            abort_if($original->reversed_at !== null, 422, 'This entry has already been reversed.');
            abort_if($original->reversal_of_id !== null, 422, 'A reversal cannot itself be reversed. Post a new entry instead.');

            $reversal = $this->post($original->store_id, [
                'date' => $date ?? now()->toDateString(),
                'memo' => "Reversal of {$original->number}: {$reason}",
                'branch_id' => $original->branch_id,
                'posting_key' => "rev:{$original->id}",
                'reversal_of_id' => $original->id,
                'user_id' => $actor->id,
                'lines' => $original->lines->map(fn ($l) => [
                    'account' => (int) $l->account_id, 'debit' => $l->credit, 'credit' => $l->debit,
                    'branch_id' => $l->branch_id, 'memo' => $l->memo,
                ])->all(),
            ]);

            $original->update(['reversed_at' => now()]);

            return $reversal;
        });
    }

    /** Resolves account roles/ids to ids, drops empty lines and rejects lines with both sides. */
    private function normalise(int $storeId, array $lines): array
    {
        $out = [];

        foreach ($lines as $i => $line) {
            $debit = (int) ($line['debit'] ?? 0);
            $credit = (int) ($line['credit'] ?? 0);

            if ($debit < 0 || $credit < 0 || ($debit > 0 && $credit > 0)) {
                throw ValidationException::withMessages(["lines.$i" => 'A line must have either a debit or a credit amount, not both.']);
            }

            if ($debit === 0 && $credit === 0) {
                continue;
            }

            $out[] = [
                'account_id' => $this->accountId($storeId, $line['account'], $i),
                'debit' => $debit, 'credit' => $credit,
                'branch_id' => $line['branch_id'] ?? null, 'memo' => $line['memo'] ?? null,
            ];
        }

        if (count($out) < 2) {
            throw ValidationException::withMessages(['lines' => 'An entry needs at least two lines with amounts.']);
        }

        return $out;
    }

    private function assertBalanced(array $lines): int
    {
        $debit = array_sum(array_column($lines, 'debit'));
        $credit = array_sum(array_column($lines, 'credit'));

        if ($debit !== $credit) {
            throw ValidationException::withMessages(['lines' => sprintf(
                'The entry does not balance: debits are %s and credits are %s.', number_format($debit / 100, 2), number_format($credit / 100, 2),
            )]);
        }

        return $debit;
    }

    private function accountId(int $storeId, int|string $ref, int $line): int
    {
        $query = Account::withoutGlobalScopes()->where('store_id', $storeId)->where('is_active', true);
        $id = is_int($ref) ? $query->whereKey($ref)->value('id') : (clone $query)->where('role', $ref)->value('id');

        // A system account that was switched off, renumbered over or never created would block every sale. Put it right once.
        if (! $id && is_string($ref) && app(ChartDefaults::class)->repair($storeId, $ref)) {
            $id = Account::withoutGlobalScopes()->where('store_id', $storeId)->where('is_active', true)->where('role', $ref)->value('id');
        }

        if (! $id) {
            throw ValidationException::withMessages(["lines.$line.account" => is_int($ref)
                ? 'That account does not exist or is disabled.' : "The store has no active account for '{$ref}'."]);
        }

        return $id;
    }
}
