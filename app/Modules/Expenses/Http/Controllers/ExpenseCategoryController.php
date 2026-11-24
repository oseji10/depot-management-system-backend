<?php

namespace App\Modules\Expenses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExpenseCategoryController extends Controller
{
    public function __construct(private AccessScope $access)
    {
    }

    public function index(Request $request): JsonResponse
    {
        // A business that has none yet gets the starting set, so the lists are never empty.
        if (! ExpenseCategory::withTrashed()->exists() && ($storeId = $this->access->storeId())) {
            app(\App\Modules\Expenses\Services\ExpenseDefaults::class)->seed($storeId);
        }

        // The Expense categories page pages through all of them (switched-off ones too); pickers get the active ones.
        if ($request->has('page')) {
            return response()->json(ExpenseCategory::with('account:id,code,name')->withCount('expenses')
                ->when($request->query('search'), fn ($q, $v) => $q->where('name', 'like', "%{$v}%"))
                ->orderBy('name')->paginate(50));
        }

        return response()->json(ExpenseCategory::with('account:id,code,name')->where('is_active', true)->orderBy('name')->get());
    }

    /** The expense accounts a category can post to (for the category form, without needing access to the full chart). */
    public function accounts(): JsonResponse
    {
        return response()->json(['data' => \App\Modules\Accounting\Models\Account::query()->where('type', 'expense')->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name'])
            ->map(fn ($a) => ['id' => $a->id, 'name' => "{$a->code} · {$a->name}"])]);
    }

    public function store(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();

        return response()->json(
            ExpenseCategory::create([...$request->validate($this->rules($storeId)), 'store_id' => $storeId])->load('account:id,code,name'), 201,
        );
    }

    /** Deleting hides the expense category (it can be restored). It is refused once anything has used it. */
    public function destroy(int $id): JsonResponse
    {
        \App\Support\Deletion::remove(\App\Modules\Expenses\Models\ExpenseCategory::findOrFail($id), ['expenses' => 'category_id'], 'This category');

        return response()->json(null, 204);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $category = ExpenseCategory::findOrFail($id);
        $category->update($request->validate($this->rules($category->store_id, $category)));

        return response()->json($category->load('account:id,code,name'));
    }

    private function rules(int $storeId, ?ExpenseCategory $category = null): array
    {
        return [
            'name' => ['required', 'string', 'max:80', Rule::unique('expense_categories', 'name')->where('store_id', $storeId)->ignore($category?->id)],
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('store_id', $storeId)->where('type', 'expense')],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
