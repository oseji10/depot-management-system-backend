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
            ->paginate(min((int) $request->query('per_page', 25), 200));

        // How many products use each variation, as their smallest one or as a bigger one.
        $ids = $rows->getCollection()->pluck('id');
        $asBigger = ProductUnit::query()->whereIn('unit_id', $ids)->where('is_active', true)->where('is_base', false)
            ->selectRaw('unit_id, COUNT(DISTINCT product_id) as n')->groupBy('unit_id')->pluck('n', 'unit_id');
        $asBase = Product::query()->whereIn('base_unit_id', $ids)->selectRaw('base_unit_id, COUNT(*) as n')->groupBy('base_unit_id')->pluck('n', 'base_unit_id');
        $rows->getCollection()->transform(fn ($u) => [...$u->toArray(), 'products_count' => (int) ($asBigger[$u->id] ?? 0) + (int) ($asBase[$u->id] ?? 0)]);

        return response()->json($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();
        $data = $request->validate($this->rules($storeId));

        // Same name in different capitals is the same variation: hand back the one that exists.
        $existing = Unit::query()->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['name']))])->first();
        if ($existing) {
            if (isset($data['usual_size']) && ! $existing->usual_size) {
                $existing->update(['usual_size' => $data['usual_size']]);
            }

            return response()->json($existing->fresh(), 200);
        }

        $id = \App\Modules\Catalogue\Services\UnitNames::find($storeId, $data['name']);
        $unit = Unit::findOrFail($id);
        $unit->update(array_filter(['abbreviation' => $data['abbreviation'] ?? null, 'usual_size' => $data['usual_size'] ?? null], fn ($v) => $v !== null));

        return response()->json($unit->fresh(), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $unit = Unit::findOrFail($id);
        $data = $request->validate($this->rules($unit->store_id, $unit));

        abort_if(Unit::query()->whereKeyNot($unit->id)->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['name']))])->exists(), 422, 'Another variation already has that name.');

        $unit->update(['name' => trim($data['name']), 'usual_size' => $data['usual_size'] ?? null] + (empty($data['abbreviation']) ? [] : ['abbreviation' => $data['abbreviation']]));

        return response()->json($unit);
    }

    public function destroy(int $id): JsonResponse
    {
        $unit = Unit::findOrFail($id);

        abort_if(ProductUnit::where('unit_id', $id)->exists() || Product::where('base_unit_id', $id)->exists(), 422,
            'This variation is used by products, so it cannot be deleted.');

        $unit->delete();

        return response()->json(null, 204);
    }

    private function rules(int $storeId, ?Unit $unit = null): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            'usual_size' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'abbreviation' => ['nullable', 'string', 'max:10',
                Rule::unique('units', 'abbreviation')->where('store_id', $storeId)->ignore($unit?->id)],
        ];
    }
}
