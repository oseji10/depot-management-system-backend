<?php

namespace App\Modules\Catalogue\Services;

use App\Modules\Catalogue\Models\PriceList;
use App\Modules\Catalogue\Models\ProductPrice;
use App\Modules\Organization\Models\Store;
use App\Support\Tenancy\AccessScope;
use Illuminate\Support\Collection;

/**
 * The price a customer pays on a given day.
 *   1. Start from the default standard price list.
 *   2. Layer active promo lists on top, lowest priority first, so the highest priority wins.
 * A promo row carries the standard price as original_price so receipts and the POS can show the saving.
 */
class PriceResolver
{
    /** @return Collection<int, array{product_unit_id:int, price:string, original_price:?string, price_list:string, is_promo:bool}> */
    public function effective(?string $on = null): Collection
    {
        $storeId = app(AccessScope::class)->requireStoreId();
        $date = $on ?? now(Store::find($storeId)?->timezone ?? config('app.timezone'))->toDateString();

        $standard = [];
        $result = [];

        $default = PriceList::where('type', 'standard')->where('is_default', true)->where('is_active', true)->first();

        if ($default) {
            foreach (ProductPrice::where('price_list_id', $default->id)->get() as $p) {
                $standard[$p->product_unit_id] = $p->price;
                $result[$p->product_unit_id] = $this->row($p, $p->price, null, $default->name, false);
            }
        }

        $promos = PriceList::where('type', 'promo')->where('is_active', true)
            ->whereDate('starts_on', '<=', $date)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date))
            ->orderBy('priority')->orderBy('id')
            ->get();

        foreach ($promos as $list) {
            foreach (ProductPrice::where('price_list_id', $list->id)->get() as $p) {
                $result[$p->product_unit_id] = $this->row($p, $p->price, $standard[$p->product_unit_id] ?? null, $list->name, true);
            }
        }

        return collect($result)->values();
    }

    private function row(ProductPrice $p, string $price, ?string $original, string $list, bool $promo): array
    {
        return [
            'product_unit_id' => $p->product_unit_id,
            'price' => $price,
            'original_price' => $original,
            'price_list' => $list,
            'is_promo' => $promo,
            'updated_at' => $p->updated_at,
        ];
    }
}
