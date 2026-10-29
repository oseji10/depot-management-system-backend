<?php

namespace App\Modules\Catalogue\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalogue\Models\PriceList;
use App\Modules\Catalogue\Models\ProductPrice;
use App\Modules\Catalogue\Models\ProductUnit;
use App\Modules\Catalogue\Services\PriceResolver;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PriceListController extends Controller
{
    public function __construct(private AccessScope $access)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $rows = PriceList::query()
            ->withCount('prices')
            ->when($request->query('search'), fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->orderByDesc('is_default')->orderByDesc('starts_on')->orderBy('name')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json($rows);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(PriceList::withCount('prices')->findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();
        $data = $this->validated($request, $storeId);

        $list = DB::transaction(function () use ($data, $storeId) {
            $list = PriceList::create([...$data, 'store_id' => $storeId]);
            $this->enforceSingleDefault($list);

            return $list;
        });

        return response()->json($list, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $list = PriceList::findOrFail($id);
        $data = $this->validated($request, $list->store_id, $list);

        abort_if($list->is_default && ($data['is_default'] ?? true) === false, 422, 'Make another standard list the default first.');

        DB::transaction(function () use ($list, $data) {
            $list->update($data);
            $this->enforceSingleDefault($list);
        });

        return response()->json($list);
    }

    public function destroy(int $id): JsonResponse
    {
        $list = PriceList::findOrFail($id);

        abort_if($list->is_default, 422, 'The default price list cannot be deleted.');

        $list->delete();

        return response()->json(null, 204);
    }

    /** Every active product unit with its price in this list (or null), plus the standard price for reference. */
    public function prices(int $id): JsonResponse
    {
        $list = PriceList::findOrFail($id);
        $default = PriceList::where('is_default', true)->first();

        $own = ProductPrice::where('price_list_id', $list->id)->pluck('price', 'product_unit_id');
        $standard = $default ? ProductPrice::where('price_list_id', $default->id)->pluck('price', 'product_unit_id') : collect();

        $rows = ProductUnit::query()
            ->where('product_units.is_active', true)
            ->whereHas('product', fn ($q) => $q->where('is_active', true))
            ->with(['product:id,name,sku,size_label,packaging', 'unit:id,name,abbreviation'])
            ->get()
            ->sortBy([fn ($a, $b) => strcmp($a->product->name, $b->product->name), fn ($a, $b) => $a->factor <=> $b->factor])
            ->map(fn (ProductUnit $pu) => [
                'product_unit_id' => $pu->id,
                'product_name' => $pu->product->name,
                'sku' => $pu->product->sku,
                'size_label' => $pu->product->size_label,
                'unit_name' => $pu->unit->name,
                'factor' => $pu->factor,
                'is_base' => $pu->is_base,
                'price' => $own[$pu->id] ?? null,
                'standard_price' => $standard[$pu->id] ?? null,
            ])
            ->values();

        return response()->json(['data' => $rows]);
    }

    /** Bulk save. A null price removes that product unit from the list. */
    public function savePrices(Request $request, int $id): JsonResponse
    {
        $list = PriceList::findOrFail($id);

        $data = $request->validate([
            'prices' => ['required', 'array', 'min:1', 'max:1000'],
            'prices.*.product_unit_id' => ['required', 'integer', 'distinct'],
            'prices.*.price' => ['present', 'nullable', 'numeric', 'min:0', 'max:9999999999.99'],
        ]);

        $ids = collect($data['prices'])->pluck('product_unit_id');

        // Scoped query: ids from another store simply do not match.
        if (ProductUnit::whereIn('id', $ids)->count() !== $ids->count()) {
            throw ValidationException::withMessages(['prices' => 'One or more products are not in your catalogue.']);
        }

        DB::transaction(function () use ($list, $data) {
            foreach ($data['prices'] as $row) {
                $existing = ProductPrice::where('price_list_id', $list->id)->where('product_unit_id', $row['product_unit_id'])->first();

                if ($row['price'] === null) {
                    $existing?->delete();
                } elseif ($existing) {
                    $existing->update(['price' => $row['price']]);
                } else {
                    ProductPrice::create([
                        'store_id' => $list->store_id,
                        'price_list_id' => $list->id,
                        'product_unit_id' => $row['product_unit_id'],
                        'price' => $row['price'],
                    ]);
                }
            }
        });

        return $this->prices($id);
    }

    /** What customers pay today (or on ?on=YYYY-MM-DD). The POS and the offline sync will use this. */
    public function effective(Request $request, PriceResolver $resolver): JsonResponse
    {
        $request->validate(['on' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json(['data' => $resolver->effective($request->query('on'))]);
    }

    private function validated(Request $request, int $storeId, ?PriceList $list = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100',
                Rule::unique('price_lists', 'name')->where('store_id', $storeId)->ignore($list?->id)],
            'type' => ['required', Rule::in(['standard', 'promo'])],
            'starts_on' => ['nullable', 'date_format:Y-m-d', 'required_if:type,promo'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($data['type'] === 'standard') {
            $data['starts_on'] = $data['ends_on'] = null;
        } else {
            $data['is_default'] = false; // only a standard list can be the default
        }

        return $data;
    }

    private function enforceSingleDefault(PriceList $list): void
    {
        if ($list->is_default) {
            PriceList::whereKeyNot($list->id)->update(['is_default' => false]);
        }
    }
}
