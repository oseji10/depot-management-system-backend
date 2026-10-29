<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Http\StoreLevelOnly;
use App\Modules\Accounting\Models\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Every report reads posted journal lines only. All money values are integer kobo.
 * `balance` figures are on the account's natural side (a positive revenue balance is a credit).
 */
class ReportController extends Controller
{
    use StoreLevelOnly;

    public function trialBalance(Request $request): JsonResponse
    {
        $storeId = $this->guardStoreLevel();
        $asOf = $request->validate(['as_of' => ['nullable', 'date'], 'branch_id' => ['nullable', 'integer']])['as_of'] ?? now()->toDateString();

        $net = $this->nets($storeId, $request, fn ($q) => $q->where('e.entry_date', '<=', $asOf));

        $rows = Account::query()->orderBy('code')->get()->map(function (Account $a) use ($net) {
            $n = (int) ($net[$a->id] ?? 0);

            return ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type,
                'debit' => max($n, 0), 'credit' => max(-$n, 0)];
        })->filter(fn ($r) => $r['debit'] || $r['credit'])->values();

        $debit = $rows->sum('debit');
        $credit = $rows->sum('credit');

        return response()->json(['as_of' => $asOf, 'rows' => $rows, 'total_debit' => $debit, 'total_credit' => $credit, 'balanced' => $debit === $credit]);
    }

    public function generalLedger(Request $request): JsonResponse
    {
        $storeId = $this->guardStoreLevel();
        $data = $request->validate([
            'account_id' => ['required', 'integer'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'branch_id' => ['nullable', 'integer'],
        ]);

        $account = Account::findOrFail($data['account_id']);
        $sign = $account->isDebitNormal() ? 1 : -1;
        $from = $data['from'] ?? null;

        $opening = 0;

        if ($from) {
            $opening = $sign * (int) ($this->nets($storeId, $request, fn ($q) => $q->where('e.entry_date', '<', $from)->where('l.account_id', $account->id))[$account->id] ?? 0);
        }

        $lines = $this->base($storeId, $request)
            ->where('l.account_id', $account->id)
            ->when($from, fn ($q) => $q->where('e.entry_date', '>=', $from))
            ->when($data['to'] ?? null, fn ($q, $v) => $q->where('e.entry_date', '<=', $v))
            ->orderBy('e.entry_date')->orderBy('e.id')->orderBy('l.id')
            ->limit(2001)
            ->get(['e.id as entry_id', 'e.number', 'e.entry_date', 'e.memo', 'l.memo as line_memo', 'l.debit', 'l.credit']);

        $truncated = $lines->count() > 2000;
        $running = $opening;

        $rows = $lines->take(2000)->map(function ($l) use (&$running, $sign) {
            $running += $sign * ($l->debit - $l->credit);

            return ['entry_id' => $l->entry_id, 'number' => $l->number, 'date' => $l->entry_date, 'memo' => $l->line_memo ?: $l->memo,
                'debit' => (int) $l->debit, 'credit' => (int) $l->credit, 'balance' => $running];
        })->values();

        return response()->json([
            'account' => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'type' => $account->type],
            'opening_balance' => $opening, 'rows' => $rows, 'closing_balance' => $running, 'truncated' => $truncated,
        ]);
    }

    /** Operating result = revenue - cost of goods sold + other income - operating expenses. */
    public function incomeStatement(Request $request): JsonResponse
    {
        $storeId = $this->guardStoreLevel();
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'], 'branch_id' => ['nullable', 'integer']]);

        $net = $this->nets($storeId, $request, fn ($q) => $q->whereBetween('e.entry_date', [$data['from'], $data['to']]));

        $section = function (string $type) use ($net) {
            $sign = in_array($type, Account::DEBIT_TYPES, true) ? 1 : -1;
            $rows = Account::query()->where('type', $type)->orderBy('code')->get()
                ->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'amount' => $sign * (int) ($net[$a->id] ?? 0)])
                ->filter(fn ($r) => $r['amount'] !== 0)->values();

            return ['rows' => $rows, 'total' => (int) $rows->sum('amount')];
        };

        $revenue = $section('revenue');
        $cogs = $section('cogs');
        $other = $section('other_income');
        $expense = $section('expense');

        return response()->json([
            'from' => $data['from'], 'to' => $data['to'],
            'revenue' => $revenue, 'cogs' => $cogs, 'gross_profit' => $revenue['total'] - $cogs['total'],
            'other_income' => $other, 'expenses' => $expense,
            'operating_result' => $revenue['total'] - $cogs['total'] + $other['total'] - $expense['total'],
        ]);
    }

    public function balanceSheet(Request $request): JsonResponse
    {
        $storeId = $this->guardStoreLevel();
        $asOf = $request->validate(['as_of' => ['nullable', 'date'], 'branch_id' => ['nullable', 'integer']])['as_of'] ?? now()->toDateString();

        $net = $this->nets($storeId, $request, fn ($q) => $q->where('e.entry_date', '<=', $asOf));
        $accounts = Account::query()->orderBy('code')->get();

        $section = function (string $type, int $sign) use ($accounts, $net) {
            $rows = $accounts->where('type', $type)
                ->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'amount' => $sign * (int) ($net[$a->id] ?? 0)])
                ->filter(fn ($r) => $r['amount'] !== 0)->values();

            return ['rows' => $rows, 'total' => (int) $rows->sum('amount')];
        };

        $assets = $section('asset', 1);
        $liabilities = $section('liability', -1);
        $equity = $section('equity', -1);

        // Profit to date has not been closed into equity, so it is shown as its own line to keep the sheet balanced.
        $earnings = 0;
        foreach ($accounts as $a) {
            if (in_array($a->type, ['revenue', 'other_income'], true)) {
                $earnings -= (int) ($net[$a->id] ?? 0);
            } elseif (in_array($a->type, ['cogs', 'expense'], true)) {
                $earnings -= (int) ($net[$a->id] ?? 0);
            }
        }

        $equityTotal = $equity['total'] + $earnings;

        return response()->json([
            'as_of' => $asOf, 'assets' => $assets, 'liabilities' => $liabilities, 'equity' => $equity,
            'current_earnings' => $earnings, 'total_equity' => $equityTotal,
            'total_liabilities_and_equity' => $liabilities['total'] + $equityTotal,
            'balanced' => $assets['total'] === $liabilities['total'] + $equityTotal,
        ]);
    }

    /**
     * The books must agree with the shelves: GL inventory = cost records = what the stock ledger says is saleable.
     * Anything that disagrees is listed so it can be investigated before the month is closed.
     */
    public function inventoryReconciliation(): JsonResponse
    {
        $storeId = $this->guardStoreLevel();

        $gl = (int) (DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.store_id', $storeId)->where('e.status', 'posted')->where('a.role', 'inventory')
            ->selectRaw('COALESCE(SUM(l.debit) - SUM(l.credit), 0) AS bal')->value('bal'));

        $costs = DB::table('product_costs')->where('store_id', $storeId)->get()->keyBy('product_id');
        $saleable = DB::table('stock_balances')->where('store_id', $storeId)->where('status', 'saleable')
            ->groupBy('product_id')->selectRaw('product_id, SUM(quantity) AS qty')->pluck('qty', 'product_id');

        $names = DB::table('products')->where('store_id', $storeId)->pluck('name', 'id');
        $mismatches = [];

        foreach ($costs->keys()->merge($saleable->keys())->unique() as $pid) {
            $ledgerQty = (int) ($saleable[$pid] ?? 0);
            $costQty = (int) ($costs[$pid]->quantity ?? 0);

            if ($ledgerQty !== $costQty) {
                $mismatches[] = ['product_id' => $pid, 'name' => $names[$pid] ?? "#{$pid}", 'stock_ledger_quantity' => $ledgerQty,
                    'cost_record_quantity' => $costQty, 'difference' => $ledgerQty - $costQty];
            }
        }

        $costValue = (int) $costs->sum('value');

        return response()->json([
            'general_ledger_balance' => $gl, 'cost_records_value' => $costValue, 'value_difference' => $gl - $costValue,
            'quantity_mismatches' => $mismatches,
            'reconciled' => $gl === $costValue && $mismatches === [],
        ]);
    }

    private function base(int $storeId, Request $request)
    {
        return DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.store_id', $storeId)->where('e.status', 'posted')
            ->when($request->query('branch_id'), fn ($q, $b) => $q->where('l.branch_id', $b));
    }

    /** account_id => (debits - credits) */
    private function nets(int $storeId, Request $request, callable $filter): \Illuminate\Support\Collection
    {
        $q = $this->base($storeId, $request);
        $filter($q);

        return $q->groupBy('l.account_id')->selectRaw('l.account_id, SUM(l.debit) - SUM(l.credit) AS net')->pluck('net', 'account_id');
    }
}
