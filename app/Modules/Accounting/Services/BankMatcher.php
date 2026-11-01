<?php

namespace App\Modules\Accounting\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Pairs lines on a bank statement with the ledger lines of the same account. */
class BankMatcher
{
    /** A transfer can reach the bank a few days after it is booked. */
    public const WINDOW_DAYS = 3;

    /** Posted ledger lines of the account that no statement line has claimed yet, as signed kobo. */
    public function openLedger(int $storeId, int $accountId, string $from, string $to): Collection
    {
        return DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->leftJoin('bank_statement_matches as m', 'm.journal_line_id', '=', 'l.id')
            ->where('l.store_id', $storeId)->where('l.account_id', $accountId)->where('e.status', 'posted')
            ->whereNull('m.id')->whereBetween('e.entry_date', [$from, $to])
            ->orderBy('e.entry_date')->orderBy('l.id')
            ->get(['l.id', 'e.entry_date as date', 'e.number', 'e.memo', DB::raw('(l.debit - l.credit) as amount')])
            ->map(fn ($r) => (object) ['id' => (int) $r->id, 'date' => substr((string) $r->date, 0, 10), 'number' => $r->number, 'memo' => $r->memo, 'amount' => (int) $r->amount]);
    }

    /**
     * Matches what can be matched safely and returns how many statement lines were matched.
     * Pass 1: one ledger line with exactly the same amount near the date. Pass 2: a run of days of ledger lines that add up
     * to the amount (a POS provider paying out a day, or a few days, of card sales in one transfer).
     */
    public function auto(int $storeId, int $statementId): int
    {
        $s = DB::table('bank_statements')->where('store_id', $storeId)->where('id', $statementId)->first();
        $from = Carbon::parse($s->period_from)->subDays(self::WINDOW_DAYS)->toDateString();
        $to = Carbon::parse($s->period_to)->addDays(self::WINDOW_DAYS)->toDateString();
        $open = $this->openLedger($storeId, $s->account_id, $from, $to);
        $lines = DB::table('bank_statement_lines')->where('store_id', $storeId)->where('bank_statement_id', $statementId)->where('status', 'unmatched')->orderBy('txn_date')->orderBy('id')->get();
        $made = 0;

        $claim = function ($line, array $ids) use (&$open, &$made, $storeId) {
            DB::transaction(function () use ($line, $ids, $storeId) {
                foreach ($ids as $id) {
                    DB::table('bank_statement_matches')->insert(['store_id' => $storeId, 'bank_statement_line_id' => $line->id, 'journal_line_id' => $id]);
                }
                DB::table('bank_statement_lines')->where('id', $line->id)->update(['status' => 'matched', 'updated_at' => now()]);
            });
            $open = $open->reject(fn ($o) => in_array($o->id, $ids, true))->values();
            $made++;
        };

        $unmatched = collect();

        foreach ($lines as $line) {
            $near = $open->filter(fn ($o) => $o->amount === (int) $line->amount && abs(Carbon::parse($o->date)->diffInDays($line->txn_date, false)) <= self::WINDOW_DAYS);

            if ($near->isNotEmpty()) {
                $best = $near->sortBy(fn ($o) => abs(Carbon::parse($o->date)->diffInDays($line->txn_date, false)))->first();
                $claim($line, [$best->id]);
            } else {
                $unmatched->push($line);
            }
        }

        foreach ($unmatched as $line) {
            if ($ids = $this->daysAddingUpTo($open, $line)) {
                $claim($line, $ids);
            }
        }

        return $made;
    }

    /** Ledger line ids from one run of consecutive days ending within the window before the statement date, summing to the amount. */
    private function daysAddingUpTo(Collection $open, object $line): ?array
    {
        $end = Carbon::parse($line->txn_date);
        $window = $open->filter(fn ($o) => Carbon::parse($o->date)->between($end->copy()->subDays(self::WINDOW_DAYS), $end))->groupBy('date')->sortKeys();
        $days = $window->keys()->all();

        for ($i = 0; $i < count($days); $i++) {
            $sum = 0;
            $ids = [];

            for ($j = $i; $j < count($days); $j++) {
                foreach ($window[$days[$j]] as $o) {
                    $sum += $o->amount;
                    $ids[] = $o->id;
                }

                if ($sum === (int) $line->amount && count($ids) > 1) {
                    return $ids;
                }
            }
        }

        return null;
    }
}
