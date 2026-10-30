<?php

namespace App\Modules\Sales\Services;

use App\Modules\Catalogue\Models\Product;
use App\Modules\Catalogue\Models\ProductCategory;
use App\Modules\Containers\Models\ContainerType;
use App\Modules\Catalogue\Services\PriceResolver;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Organization\Models\Warehouse;
use App\Modules\Sales\Models\PosTerminal;
use App\Support\Money;

/** What a till needs to sell from one warehouse (or van): sellable products, today's prices in kobo, and stock on hand. */
class PosCatalogue
{
    public function __construct(private PriceResolver $prices)
    {
    }

    public function build(Warehouse $warehouse): array
    {
        $effective = $this->prices->effective()->keyBy('product_unit_id');

        $stock = StockBalance::where('warehouse_id', $warehouse->id)->where('status', 'saleable')->pluck('quantity', 'product_id');

        $products = Product::where('is_active', true)
            ->with(['units' => fn ($q) => $q->where('is_active', true)->orderBy('factor'), 'units.unit:id,name,abbreviation'])
            ->orderBy('name')->get();

        $rows = $products->map(function (Product $p) use ($effective, $stock) {
            $units = $p->units->filter(fn ($u) => isset($effective[$u->id]))->map(function ($u) use ($effective) {
                $price = $effective[$u->id];

                return [
                    'product_unit_id' => $u->id, 'unit_id' => $u->unit_id, 'name' => $u->unit?->name, 'abbreviation' => $u->unit?->abbreviation,
                    'factor' => $u->factor, 'barcode' => $u->barcode, 'price' => Money::kobo($price['price']),
                    'original_price' => $price['original_price'] !== null ? Money::kobo($price['original_price']) : null,
                    'is_promo' => $price['is_promo'],
                ];
            })->values();

            return [
                'id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'brand' => $p->brand, 'size_label' => $p->size_label,
                'packaging' => $p->packaging, 'category_id' => $p->category_id, 'is_returnable' => $p->is_returnable, 'image_url' => $p->image_url,
                'bottle_container_id' => $p->bottle_container_id, 'crate_container_id' => $p->crate_container_id, 'crate_size' => $p->crateSize(),
                'stock' => (int) ($stock[$p->id] ?? 0), 'units' => $units,
            ];
        })->filter(fn ($p) => $p['units']->isNotEmpty())->values();

        // Active products that have stock here but no price, so they can't be sold yet. The till tells the user about them.
        $unpriced = $products->filter(fn (Product $p) => ($stock[$p->id] ?? 0) > 0 && ! $p->units->contains(fn ($u) => isset($effective[$u->id])));

        // Why: a price may exist, but in a list the till does not use (not the default one, or a promo that is not running).
        $elsewhere = \Illuminate\Support\Facades\DB::table('product_prices')->join('price_lists', 'price_lists.id', '=', 'product_prices.price_list_id')
            ->join('product_units', 'product_units.id', '=', 'product_prices.product_unit_id')
            ->whereIn('product_units.product_id', $unpriced->pluck('id'))
            ->get(['product_units.product_id', 'price_lists.name', 'price_lists.type', 'price_lists.is_default', 'price_lists.is_active', 'price_lists.starts_on', 'price_lists.ends_on'])
            ->groupBy('product_id');
        $default = \App\Modules\Catalogue\Models\PriceList::where('is_default', true)->first();

        $unpriced = $unpriced->map(fn (Product $p) => [
            'id' => $p->id, 'name' => $p->name,
            'why' => match (true) {
                ! $default || ! $default->is_active => 'The default price list is switched off.',
                $twin = $products->first(fn (Product $o) => $o->id !== $p->id && mb_strtolower(trim($o->name)) === mb_strtolower(trim($p->name))) =>
                    "There are two products with this name (codes {$p->sku} and {$twin->sku}). The price may have been set on the other one; this one, which holds the stock, has none.",
                $p->units->isEmpty() => 'It has no active unit to sell in.',
                isset($elsewhere[$p->id]) => 'It is priced in "'.$elsewhere[$p->id]->pluck('name')->unique()->implode('", "').'" but not in "'.$default->name.'", the list the till uses.',
                default => 'No price is set for it in "'.$default->name.'".',
            },
        ])->values();

        return [
            'warehouse' => ['id' => $warehouse->id, 'name' => $warehouse->name, 'branch_id' => $warehouse->branch_id],
            'categories' => ProductCategory::orderBy('name')->get(['id', 'name']),
            'terminals' => PosTerminal::usableAt($warehouse->branch_id, (int) auth()->id())->orderBy('name')->get(['id', 'name', 'provider']),
            'containers' => ContainerType::where('is_active', true)->orderBy('name')->get(['id', 'name', 'kind', 'deposit']),
            'products' => $rows,
            'unpriced' => $unpriced,
            'mix_layouts' => \App\Modules\Sales\Models\MixLayout::where('is_active', true)->orderBy('row_size')->orderBy('row_count')->get(['id', 'name', 'row_count', 'row_size']),
        ];
    }
}
