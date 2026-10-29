<?php

namespace App\Modules\Catalogue\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalogue\Models\RebateCategory;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RebateCategoryController extends Controller
{
    public function __construct(private AccessScope $access)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $rows = RebateCategory::query()
            ->withCount('products')
            ->when($request->query('search'), fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->orderBy('sort_order')->orderBy('name')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();

        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'alpha_dash',
                Rule::unique('rebate_categories', 'code')->where('store_id', $storeId)],
            'name' => ['required', 'string', 'max:100'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65000'],
        ]);

        return response()->json(RebateCategory::create([...$data, 'store_id' => $storeId]), 201);
    }

    /** The code is what rebate rules will reference, so only the label, order and status can change. */
    public function update(Request $request, int $id): JsonResponse
    {
        $category = RebateCategory::findOrFail($id);

        $category->update($request->validate([
            'name' => ['required', 'string', 'max:100'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65000'],
            'is_active' => ['sometimes', 'boolean'],
        ]));

        return response()->json($category);
    }
}
