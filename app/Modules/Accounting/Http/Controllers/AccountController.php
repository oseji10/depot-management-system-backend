<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Http\StoreLevelOnly;
use App\Modules\Accounting\Models\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    use StoreLevelOnly;

    public function index(Request $request): JsonResponse
    {
        $storeId = $this->guardStoreLevel();

        $balances = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.store_id', $storeId)->where('e.status', 'posted')
            ->groupBy('l.account_id')->selectRaw('l.account_id, SUM(l.debit) - SUM(l.credit) AS net')->pluck('net', 'account_id');

        $rows = Account::query()
            ->when($request->query('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")))
            ->orderBy('code')
            ->paginate(min((int) $request->query('per_page', 100), 200));

        // Balance is shown on the account's natural side: positive means the usual direction.
        $rows->getCollection()->transform(function (Account $a) use ($balances) {
            $net = (int) ($balances[$a->id] ?? 0);
            $a->setAttribute('balance', $a->isDebitNormal() ? $net : -$net);

            return $a;
        });

        return response()->json($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $storeId = $this->guardStoreLevel();

        return response()->json(Account::create([...$request->validate($this->rules($storeId)), 'store_id' => $storeId]), 201);
    }

    /** System accounts keep their type; any account that already has postings keeps its type too. */
    public function update(Request $request, int $id): JsonResponse
    {
        $account = Account::findOrFail($id);
        $data = $request->validate($this->rules($account->store_id, $account));

        $locked = $account->is_system || DB::table('journal_lines')->where('account_id', $account->id)->exists();

        if ($locked && $data['type'] !== $account->type) {
            abort(422, 'The type of this account cannot change because it is a system account or already has postings.');
        }

        abort_if($account->is_system && ! ($data['is_active'] ?? true), 422, 'System accounts cannot be disabled.');

        $account->update($data);

        return response()->json($account);
    }

    private function rules(int $storeId, ?Account $account = null): array
    {
        return [
            'code' => ['required', 'string', 'max:10', Rule::unique('accounts', 'code')->where('store_id', $storeId)->ignore($account?->id)],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(Account::TYPES)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
