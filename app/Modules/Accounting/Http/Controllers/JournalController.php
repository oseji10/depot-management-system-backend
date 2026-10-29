<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Http\StoreLevelOnly;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\Ledger;
use App\Modules\Inventory\Support\Approvals;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class JournalController extends Controller
{
    use StoreLevelOnly;

    private const WITH = ['creator:id,name', 'poster:id,name', 'branch:id,name'];

    public function __construct(private Ledger $ledger)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->guardStoreLevel();

        $rows = JournalEntry::query()
            ->with(self::WITH)
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('from'), fn ($q, $v) => $q->where('entry_date', '>=', $v))
            ->when($request->query('to'), fn ($q, $v) => $q->where('entry_date', '<=', $v))
            ->when($request->query('account_id'), fn ($q, $v) => $q->whereHas('lines', fn ($l) => $l->where('account_id', $v)))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('number', 'like', "%{$s}%")->orWhere('memo', 'like', "%{$s}%")))
            ->orderByDesc('entry_date')->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json($rows);
    }

    public function show(int $id): JsonResponse
    {
        $this->guardStoreLevel();

        return response()->json(JournalEntry::with([...self::WITH, 'lines.account:id,code,name'])->findOrFail($id));
    }

    /** Manual entry. Amounts arrive in naira and are converted to kobo here. `post: true` also posts it. */
    public function store(Request $request): JsonResponse
    {
        $storeId = $this->guardStoreLevel();
        $user = $request->user();

        $data = $request->validate([
            'entry_date' => ['required', 'date'],
            'memo' => ['required', 'string', 'max:255'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('store_id', $storeId)],
            'post' => ['sometimes', 'boolean'],
            'lines' => ['required', 'array', 'min:2', 'max:100'],
            'lines.*.account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('store_id', $storeId)],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0', 'max:100000000000'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0', 'max:100000000000'],
            'lines.*.memo' => ['nullable', 'string', 'max:255'],
        ]);

        $post = (bool) ($data['post'] ?? false);
        abort_if($post && ! $user->can('journals.post'), 403, 'You can save a draft, but you cannot post journals.');

        $spec = [
            'date' => $data['entry_date'], 'memo' => $data['memo'], 'branch_id' => $data['branch_id'] ?? null, 'user_id' => $user->id,
            'lines' => array_map(fn ($l) => [
                'account' => (int) $l['account_id'], 'debit' => Money::kobo($l['debit'] ?? 0), 'credit' => Money::kobo($l['credit'] ?? 0),
                'memo' => $l['memo'] ?? null,
            ], $data['lines']),
        ];

        $entry = $post ? $this->ledger->post($storeId, $spec) : $this->ledger->draft($storeId, $spec);

        return response()->json($entry->load('lines'), 201);
    }

    public function post(Request $request, int $id): JsonResponse
    {
        $this->guardStoreLevel();

        $entry = JournalEntry::findOrFail($id);
        Approvals::assertCanDecide($request->user(), $entry->created_by);

        $this->ledger->postDraft($id, $request->user());

        return $this->show($id);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->guardStoreLevel();

        $entry = JournalEntry::findOrFail($id);
        abort_unless($entry->status === 'draft', 422, 'Only a draft can be deleted. Reverse a posted entry instead.');

        DB::transaction(function () use ($entry) {
            $entry->lines()->delete();
            $entry->delete();
        });

        return response()->json(null, 204);
    }

    public function reverse(Request $request, int $id): JsonResponse
    {
        $this->guardStoreLevel();

        $data = $request->validate(['reason' => ['required', 'string', 'max:200'], 'date' => ['nullable', 'date']]);

        $reversal = $this->ledger->reverse($id, $request->user(), $data['reason'], $data['date'] ?? null);

        return response()->json($reversal->load('lines'), 201);
    }
}
