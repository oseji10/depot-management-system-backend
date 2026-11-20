<?php

namespace App\Modules\Catalogue\Services;

use App\Modules\Accounting\Services\InventoryCosting;
use App\Modules\Catalogue\Models\Product;
use App\Modules\Organization\Models\Warehouse;
use App\Modules\Inventory\Services\StockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock and cost for anything sold: an ordinary product moves itself, a bundle moves the products inside it.
 * Every sale, void and return goes through here, so a bundle never holds stock of its own.
 */
class BundleStock
{
    /** @var array<int, array<int, int>|null> product id => [component id => base quantity per bundle], or null if not a bundle */
    private array $cache = [];

    public function __construct(private StockService $stock, private InventoryCosting $costing)
    {
    }

    /** @return array<int, int>|null */
    public function components(int $productId, bool $strict = true): ?array
    {
        if (array_key_exists($productId, $this->cache)) {
            abort_if($strict && $this->cache[$productId] === [], 422, 'This bundle has no products in it yet.');

            return $this->cache[$productId];
        }

        if (! Schema::hasColumn('products', 'is_bundle') || ! DB::table('products')->where('id', $productId)->value('is_bundle')) {
            return $this->cache[$productId] = null;
        }

        $parts = DB::table('product_bundle_items')->where('bundle_id', $productId)
            ->selectRaw('product_id, SUM(base_quantity) AS qty')->groupBy('product_id')->pluck('qty', 'product_id')
            ->map(fn ($v) => (int) $v)->all();

        $this->cache[$productId] = $parts;
        abort_if($strict && $parts === [], 422, 'This bundle has no products in it yet.');

        return $parts;
    }

    /** Signed quantity of the product sold (in its smallest unit). For a bundle, each item moves by quantity × its share. */
    public function move(Warehouse $warehouse, int $productId, string $status, int $quantity, string $type, array $meta = []): void
    {
        $parts = $this->components($productId);

        if ($parts === null) {
            $this->stock->move($warehouse, $productId, $status, $quantity, $type, $meta);

            return;
        }

        $bundle = Product::withoutGlobalScopes()->find($productId);

        foreach ($parts as $componentId => $per) {
            $this->stock->move($warehouse, $componentId, $status, $quantity * $per, $type, [
                ...$meta,
                'note' => trim(($meta['note'] ?? '').' (in bundle '.($bundle?->name ?? '#'.$productId).')'),
                'idempotency_key' => isset($meta['idempotency_key']) ? "{$meta['idempotency_key']}:c{$componentId}" : null,
            ]);
        }
    }

    /** Cost of what leaves stock: a bundle costs the sum of its items. */
    public function issue(int $storeId, int $productId, int $quantity): int
    {
        $parts = $this->components($productId);

        if ($parts === null) {
            return $this->costing->issue($storeId, $productId, $quantity);
        }

        $cost = 0;
        foreach ($parts as $componentId => $per) {
            $cost += $this->costing->issue($storeId, $componentId, $quantity * $per);
        }

        return $cost;
    }

    /** Put cost back with returned goods, shared between a bundle's items in proportion to what they are worth now. */
    public function add(int $storeId, int $productId, int $quantity, int $value): void
    {
        $parts = $this->components($productId);

        if ($parts === null) {
            $this->costing->add($storeId, $productId, $quantity, $value);

            return;
        }

        $weights = [];
        foreach ($parts as $componentId => $per) {
            $weights[$componentId] = max(1, $this->costing->valueOf($storeId, $componentId, $quantity * $per));
        }

        $sum = array_sum($weights);
        $left = $value;
        $last = array_key_last($parts);

        foreach ($parts as $componentId => $per) {
            $share = $componentId === $last ? $left : intdiv($value * $weights[$componentId], $sum);
            $left -= $share;
            $this->costing->add($storeId, $componentId, $quantity * $per, $share);
        }
    }

    /**
     * How many of each bundle can be made from the stock given (product id => quantity on hand).
     *
     * @param  iterable<int>  $bundleIds
     * @param  array<int, int>|\ArrayAccess  $stock
     * @return array<int, int>
     */
    public function available(iterable $bundleIds, $stock): array
    {
        $out = [];

        foreach ($bundleIds as $id) {
            $parts = $this->components($id, false) ?? [];
            $out[$id] = $parts === [] ? 0 : (int) min(array_map(fn ($c, $per) => intdiv(max(0, (int) ($stock[$c] ?? 0)), max(1, $per)), array_keys($parts), $parts));
        }

        return $out;
    }
}
