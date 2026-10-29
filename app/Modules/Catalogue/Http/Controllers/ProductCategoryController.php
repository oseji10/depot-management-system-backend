<?php

namespace App\Modules\Catalogue\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalogue\Models\ProductCategory;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductCategoryController extends Controller
{
    public function __construct(private AccessScope $access)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $rows = ProductCategory::query()
            ->with('parent:id,name')
            ->withCount('products')
            ->when($request->query('search'), fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->orderBy('sort_order')->orderBy('name')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();
        $data = $request->validate($this->rules($storeId));
        $this->assertNesting($data['parent_id'] ?? null);

        return response()->json(ProductCategory::create([...$data, 'store_id' => $storeId]), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $category = ProductCategory::findOrFail($id);
        $data = $request->validate($this->rules($category->store_id, $category));
        $this->assertNesting($data['parent_id'] ?? null, $category);

        $category->update($data);

        return response()->json($category);
    }

    public function destroy(int $id): JsonResponse
    {
        $category = ProductCategory::findOrFail($id);

        abort_if($category->products()->exists() || $category->children()->exists(), 422,
            'Move this category\'s products and sub-categories first, or disable it instead.');

        $category->delete();

        return response()->json(null, 204);
    }

    /** One level of nesting keeps browsing simple: RGB (Crates) > Sparkling, for example. */
    private function assertNesting(?int $parentId, ?ProductCategory $self = null): void
    {
        if ($parentId === null) {
            return;
        }

        abort_if($self && $parentId === $self->id, 422, 'A category cannot be its own parent.');
        abort_if(ProductCategory::findOrFail($parentId)->parent_id !== null, 422, 'Categories can only be nested one level deep.');
        abort_if($self && $self->children()->exists(), 422, 'This category has sub-categories, so it cannot be nested itself.');
    }

    private function rules(int $storeId, ?ProductCategory $category = null): array
    {
        return [
            'name' => ['required', 'string', 'max:100',
                Rule::unique('product_categories', 'name')->where('store_id', $storeId)->ignore($category?->id)],
            'parent_id' => ['nullable', 'integer', Rule::exists('product_categories', 'id')->where('store_id', $storeId)],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
