<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\BankStatement;
use App\Modules\Accounting\Models\BankStatementLine;
use App\Modules\Accounting\Services\BankMatcher;
use App\Modules\Accounting\Services\Ledger;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Importing a bank (or card-provider) statement and ticking it off against the books. */
class BankReconciliationController extends Controller
{
    public function __construct(private AccessScope $access, private BankMatcher $matcher, private Ledger $ledger)
    {
    }

    /** Accounts that can have a statement: the bank, and the account behind each card terminal. */
    public function accounts(): JsonResponse
    {
        $terminalAccounts = DB::table('pos_terminals')->where('store_id', $this->access->requireStoreId())->whereNotNull('account_id')->pluck('account_id');

        return response()->json(['data' => Account::query()->where(fn ($q) => $q->where('role', 'bank')->orWhereIn('id', $terminalAccounts))->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name'])]);
    }

    public function index(): JsonResponse
    {
        $rows = BankStatement::query()->with('account:id,code,name')->withCount([
            'lines', 'lines as open_count' => fn ($q) => $q->where('status', 'unmatched'),
        ])->orderByDesc('period_to')->orderByDesc('id')->limit(100)->get();

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();
        $data = $request->validate([
            'account_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:120'],
            'opening_balance' => ['nullable', 'integer'],
            'closing_balance' => ['nullable', 'integer'],
            'lines' => ['required', 'array', 'min:1', 'max:3000'],
            'lines.*.date' => ['required', 'date_format:Y-m-d'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.reference' => ['nullable', 'string', 'max:120'],
            'lines.*.amount' => ['required', 'integer', 'not_in:0', 'between:-100000000000,100000000000'],
        ]);

        $account = Account::findOrFail($data['account_id']);
        $dates = collect($data['lines'])->pluck('date');

        $statement = DB::transaction(function () use ($data, $account, $dates, $storeId, $request) {
            $s = BankStatement::create([
                'store_id' => $storeId, 'account_id' => $account->id, 'name' => $data['name'], 'period_from' => $dates->min(), 'period_to' => $dates->max(),
                'opening_balance' => $data['opening_balance'] ?? null, 'closing_balance' => $data['closing_balance'] ?? null, 'created_by' => $request->user()->id,
            ]);

            foreach (array_chunk($data['lines'], 300) as $chunk) {
                BankStatementLine::insert(array_map(fn ($l) => [
                    'store_id' => $storeId, 'bank_statement_id' => $s->id, 'txn_date' => $l['date'], 'description' => $l['description'] ?? null,
                    'reference' => $l['reference'] ?? null, 'amount' => $l['amount'], 'status' => 'unmatched', 'created_at' => now(), 'updated_at' => now(),
                ], $chunk));
            }

            return $s;
        });

        $matched = $this->matcher->auto($storeId, $statement->id);

        return response()->json([...$this->view($statement->id), 'auto_matched' => $matched], 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json($this->view($id));
    }

    public function destroy(int $id): JsonResponse
    {
        BankStatement::findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }

    public function auto(int $id): JsonResponse
    {
        $matched = $this->matcher->auto($this->access->requireStoreId(), BankStatement::findOrFail($id)->id);

        return response()->json([...$this->view($id), 'auto_matched' => $matched]);
    }

    public function match(Request $request, int $id, int $lineId): JsonResponse
    {
        $storeId = $this->access->requireStoreId();
        $data = $request->validate(['journal_line_ids' => ['required', 'array', 'min:1', 'max:200'], 'journal_line_ids.*' => ['integer']]);
        $statement = BankStatement::findOrFail($id);
        $line = BankStatementLine::where('bank_statement_id', $id)->findOrFail($lineId);
        abort_unless($line->status === 'unmatched', 422, 'That line is already dealt with. Undo it first.');

        $ledger = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->leftJoin('bank_statement_matches as m', 'm.journal_line_id', '=', 'l.id')
            ->where('l.store_id', $storeId)->where('l.account_id', $statement->account_id)->where('e.status', 'posted')->whereNull('m.id')
            ->whereIn('l.id', $data['journal_line_ids'])->get(['l.id', DB::raw('(l.debit - l.credit) as amount')]);

        abort_unless($ledger->count() === count(array_unique($data['journal_line_ids'])), 422, 'One of those ledger lines is not available for this account.');
        abort_unless((int) $ledger->sum('amount') === (int) $line->amount, 422, 'The ledger lines you picked add up to '.number_format($ledger->sum('amount') / 100, 2).', not '.number_format($line->amount / 100, 2).'.');

        DB::transaction(function () use ($ledger, $line, $storeId) {
            foreach ($ledger as $l) {
                DB::table('bank_statement_matches')->insert(['store_id' => $storeId, 'bank_statement_line_id' => $line->id, 'journal_line_id' => $l->id]);
            }
            $line->update(['status' => 'matched']);
        });

        return response()->json($this->view($id));
    }

    /** Undo a match or an ignore. */
    public function unmatch(int $id, int $lineId): JsonResponse
    {
        $line = BankStatementLine::where('bank_statement_id', $id)->findOrFail($lineId);
        DB::transaction(function () use ($line) {
            DB::table('bank_statement_matches')->where('bank_statement_line_id', $line->id)->delete();
            $line->update(['status' => 'unmatched', 'note' => null]);
        });

        return response()->json($this->view($id));
    }

    public function ignore(Request $request, int $id, int $lineId): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:255']]);
        $line = BankStatementLine::where('bank_statement_id', $id)->findOrFail($lineId);
        abort_unless($line->status === 'unmatched', 422, 'That line is already dealt with.');
        $line->update(['status' => 'ignored', 'note' => $data['note']]);

        return response()->json($this->view($id));
    }

    /** The bank did something the books do not know about (a charge, interest, a deposit): book it and match it in one go. */
    public function book(Request $request, int $id, int $lineId): JsonResponse
    {
        $storeId = $this->access->requireStoreId();
        $data = $request->validate(['account_id' => ['required', 'integer'], 'memo' => ['nullable', 'string', 'max:200']]);
        $statement = BankStatement::findOrFail($id);
        $line = BankStatementLine::where('bank_statement_id', $id)->findOrFail($lineId);
        abort_unless($line->status === 'unmatched', 422, 'That line is already dealt with.');
        $other = Account::findOrFail($data['account_id']);
        abort_if($other->id === $statement->account_id, 422, 'Pick the account the money came from or went to, not the bank itself.');

        DB::transaction(function () use ($data, $statement, $line, $other, $storeId, $request) {
            $amount = abs($line->amount);
            $in = $line->amount > 0;
            $entry = $this->ledger->post($storeId, [
                'date' => $line->txn_date->toDateString(), 'memo' => $data['memo'] ?: ($line->description ?: 'Bank statement entry'), 'source' => $statement,
                'posting_key' => 'bankline:'.$line->id, 'user_id' => $request->user()->id,
                'lines' => [
                    ['account' => $statement->account_id, 'debit' => $in ? $amount : 0, 'credit' => $in ? 0 : $amount],
                    ['account' => $other->id, 'debit' => $in ? 0 : $amount, 'credit' => $in ? $amount : 0],
                ],
            ]);

            $jl = DB::table('journal_lines')->where('journal_entry_id', $entry->id)->where('account_id', $statement->account_id)->value('id');
            DB::table('bank_statement_matches')->insert(['store_id' => $storeId, 'bank_statement_line_id' => $line->id, 'journal_line_id' => $jl]);
            $line->update(['status' => 'matched', 'note' => 'Booked: '.$other->name]);
        });

        return response()->json($this->view($id));
    }

    private function view(int $id): array
    {
        $storeId = $this->access->requireStoreId();
        $statement = BankStatement::with('account:id,code,name')->findOrFail($id);
        $lines = BankStatementLine::where('bank_statement_id', $id)->orderBy('txn_date')->orderBy('id')->get();

        $matched = DB::table('bank_statement_matches as m')->join('journal_lines as l', 'l.id', '=', 'm.journal_line_id')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->whereIn('m.bank_statement_line_id', $lines->pluck('id'))
            ->get(['m.bank_statement_line_id as line_id', 'l.id', 'e.entry_date as date', 'e.number', 'e.memo', DB::raw('(l.debit - l.credit) as amount')])->groupBy('line_id');

        $from = $statement->period_from->copy()->subDays(BankMatcher::WINDOW_DAYS)->toDateString();
        $to = $statement->period_to->copy()->addDays(BankMatcher::WINDOW_DAYS)->toDateString();
        $open = $this->matcher->openLedger($storeId, $statement->account_id, $from, $to);

        $ledgerBalance = (int) DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.store_id', $storeId)->where('l.account_id', $statement->account_id)->where('e.status', 'posted')->where('e.entry_date', '<=', $statement->period_to->toDateString())
            ->selectRaw('COALESCE(SUM(l.debit) - SUM(l.credit), 0) AS n')->value('n');

        $unmatched = $lines->where('status', 'unmatched');
        $openInPeriod = $open->filter(fn ($o) => $o->date >= $statement->period_from->toDateString() && $o->date <= $statement->period_to->toDateString());

        return [
            'statement' => $statement,
            'lines' => $lines->map(fn ($l) => [...$l->toArray(), 'txn_date' => $l->txn_date->toDateString(), 'ledger' => ($matched[$l->id] ?? collect())->map(fn ($m) => (array) $m)->values()]),
            'ledger_open' => $open->values(),
            'summary' => [
                'statement_closing' => $statement->closing_balance, 'ledger_balance' => $ledgerBalance,
                'difference' => $statement->closing_balance === null ? null : $statement->closing_balance - $ledgerBalance,
                'unmatched_statement' => (int) $unmatched->sum('amount'), 'unmatched_statement_count' => $unmatched->count(),
                'unmatched_ledger' => (int) $openInPeriod->sum('amount'), 'unmatched_ledger_count' => $openInPeriod->count(),
                'matched_count' => $lines->where('status', 'matched')->count(), 'ignored_count' => $lines->where('status', 'ignored')->count(),
            ],
        ];
    }
}
