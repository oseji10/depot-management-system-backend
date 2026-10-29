<?php

namespace App\Modules\Catalogue\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalogue\Models\Product;
use App\Modules\Catalogue\Models\ProductUnit;
use App\Modules\Catalogue\Models\Unit;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UnitController extends Controller
{
    public function __construct(private AccessScope $access)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $rows = Unit::query()
            ->when($request->query('search'), fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->orderBy('name')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();

        return response()->json(Unit::create([...$request->validate($this->rules($storeId)), 'store_id' => $storeId]), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $unit = Unit::findOrFail($id);
        $unit->update($request->validate($this->rules($unit->store_id, $unit)));

        return response()->json($unit);
    }

    public function destroy(int $id): JsonResponse
    {
        $unit = Unit::findOrFail($id);

        abort_if(ProductUnit::where('unit_id', $id)->exists() || Product::where('base_unit_id', $id)->exists(), 422,
            'This unit is used by products, so it cannot be deleted.');

        $unit->delete();

        return response()->json(null, 204);
    }

    private function rules(int $storeId, ?Unit $unit = null): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            'abbreviation' => ['required', 'string', 'max:10',
                Rule::unique('units', 'abbreviation')->where('store_id', $storeId)->ignore($unit?->id)],
        ];
    }
}
