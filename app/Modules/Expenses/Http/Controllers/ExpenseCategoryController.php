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

    public function index(): JsonResponse
    {
        return response()->json(ExpenseCategory::with('account:id,code,name')->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();

        return response()->json(
            ExpenseCategory::create([...$request->validate($this->rules($storeId)), 'store_id' => $storeId])->load('account:id,code,name'), 201,
        );
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
