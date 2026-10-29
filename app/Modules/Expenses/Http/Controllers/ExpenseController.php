<?php

namespace App\Modules\Expenses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Services\ExpenseService;
use App\Modules\Organization\Models\Warehouse;
use App\Support\Tenancy\AccessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
    private const WITH = ['category:id,name', 'branch:id,name', 'creator:id,name', 'trip:id,number'];

    public function __construct(private AccessScope $access, private ExpenseService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $q = $this->filtered($request);

        $page = (clone $q)->with(self::WITH)->orderByDesc('expense_date')->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        $posted = (clone $q)->where('status', 'posted');

        return response()->json([
            ...$page->toArray(),
            'summary' => [
                'total' => (int) (clone $posted)->sum('amount'),
                'count' => (clone $posted)->count(),
                'by_category' => (clone $posted)->join('expense_categories', 'expense_categories.id', '=', 'expenses.category_id')
                    ->groupBy('expense_categories.name')->selectRaw('expense_categories.name AS name, SUM(expenses.amount) AS total')
                    ->orderByDesc('total')->get()->map(fn ($r) => ['name' => $r->name, 'total' => (int) $r->total])->values(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();

        $data = $request->validate([
            'category_id' => ['required', 'integer', Rule::exists('expense_categories', 'id')->where('store_id', $storeId)],
            'amount' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'method' => ['required', Rule::in(Expense::METHODS)],
            'expense_date' => ['nullable', 'date_format:Y-m-d'],
            'paid_to' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'client_ref' => ['nullable', 'string', 'max:64'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('store_id', $storeId)],
        ]);

        $expense = $this->service->create($data, $request->user());

        return response()->json($this->visible()->with(self::WITH)->findOrFail($expense->id), 201);
    }

    public function void(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $this->visible()->findOrFail($id);

        return response()->json($this->service->void($id, $data['reason'], $request->user())->load(self::WITH));
    }

    /** Salespeople see only what they logged themselves. */
    private function visible(): Builder
    {
        $user = request()->user();

        return Expense::query()->when(Warehouse::vanOf($user->id), fn ($q) => $q->where('expenses.created_by', $user->id));
    }

    private function filtered(Request $request): Builder
    {
        return $this->visible()
            ->when($request->query('status'), fn ($q, $v) => $q->where('expenses.status', $v))
            ->when($request->query('category_id'), fn ($q, $v) => $q->where('expenses.category_id', $v))
            ->when($request->query('branch_id'), fn ($q, $v) => $q->where('expenses.branch_id', $v))
            ->when($request->query('trip_id'), fn ($q, $v) => $q->where('expenses.trip_id', $v))
            ->when($request->query('from'), fn ($q, $v) => $q->where('expenses.expense_date', '>=', $v))
            ->when($request->query('to'), fn ($q, $v) => $q->where('expenses.expense_date', '<=', $v))
            ->when($request->query('search'), fn ($q, $s) => $q->where(
                fn ($w) => $w->where('expenses.number', 'like', "%{$s}%")->orWhere('expenses.paid_to', 'like', "%{$s}%")->orWhere('expenses.description', 'like', "%{$s}%")
            ));
    }
}
