<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Http\StoreLevelOnly;
use App\Modules\Accounting\Models\Account;
use App\Modules\Sales\Models\PosTerminal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The business's real bank accounts. Each one is an asset account on the chart (so takings are booked against it)
 * that also carries the bank name and account number, so cashiers can pick it when a customer pays by transfer.
 */
class BankAccountController extends Controller
{
    use StoreLevelOnly;

    public function index(): JsonResponse
    {
        $this->guardStoreLevel();

        $balances = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.status', 'posted')->groupBy('l.account_id')->selectRaw('l.account_id, SUM(l.debit) - SUM(l.credit) AS net')->pluck('net', 'account_id');

        $machines = PosTerminal::whereNotNull('account_id')->get(['id', 'name', 'account_id'])->groupBy('account_id');

        $rows = Account::where('type', 'asset')->where(fn ($q) => $q->where('is_bank', true)->orWhere('role', 'bank'))->orderBy('code')->get()
            ->map(fn (Account $a) => [
                ...$a->only(['id', 'code', 'name', 'bank_name', 'account_number', 'account_name', 'is_active']),
                'is_main' => $a->role === 'bank', 'balance' => (int) ($balances[$a->id] ?? 0),
                'terminals' => ($machines[$a->id] ?? collect())->map(fn ($t) => $t->only(['id', 'name']))->values(),
            ]);

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $storeId = $this->guardStoreLevel();
        $data = $request->validate($this->rules());

        $account = DB::transaction(function () use ($storeId, $data) {
            $taken = Account::withoutGlobalScopes()->where('store_id', $storeId)->pluck('code')->all();
            $code = null;
            foreach (range(1011, 1099) as $try) {
                if (! in_array((string) $try, $taken, true)) {
                    $code = (string) $try;
                    break;
                }
            }
            abort_if($code === null, 422, 'No free account code is left for another bank account. Switch off one you no longer use.');

            return Account::create([...$data, 'store_id' => $storeId, 'code' => $code, 'type' => 'asset', 'is_bank' => true, 'is_active' => true]);
        });

        return response()->json($account, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $account = Account::where('type', 'asset')->where(fn ($q) => $q->where('is_bank', true)->orWhere('role', 'bank'))->findOrFail($id);
        $data = $request->validate([...$this->rules(), 'is_active' => ['sometimes', 'boolean']]);

        abort_if($account->role === 'bank' && ! ($data['is_active'] ?? true), 422, 'The main Bank account cannot be switched off.');

        $account->update($data);

        return response()->json($account);
    }

    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'bank_name' => ['required', 'string', 'max:80'],
            'account_number' => ['nullable', 'string', 'regex:/^[0-9]{6,20}$/'],
            'account_name' => ['nullable', 'string', 'max:120'],
        ];
    }
}
