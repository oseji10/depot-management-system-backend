<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalogue\Models\Product;
use App\Modules\Catalogue\Models\ProductUnit;
use App\Modules\Inventory\Models\ReorderLevel;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Organization\Models\Warehouse;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StockController extends Controller
{
    public function __construct(private AccessScope $access)
    {
    }

    /** One row per warehouse and product, with the saleable / damaged / expired split. */
    public function onHand(Request $request): JsonResponse
    {
        $paginator = StockBalance::query()
            ->selectRaw("stock_balances.warehouse_id, stock_balances.product_id,
                SUM(CASE WHEN stock_balances.status = 'saleable' THEN stock_balances.quantity ELSE 0 END) AS saleable,
                SUM(CASE WHEN stock_balances.status = 'damaged' THEN stock_balances.quantity ELSE 0 END) AS damaged,
                SUM(CASE WHEN stock_balances.status = 'expired' THEN stock_balances.quantity ELSE 0 END) AS expired,
                SUM(stock_balances.quantity) AS total")
            ->join('products', 'products.id', '=', 'stock_balances.product_id')
            ->groupBy('stock_balances.warehouse_id', 'stock_balances.product_id', 'products.name')
            ->orderBy('products.name')
            ->when($request->query('warehouse_id'), fn ($q, $v) => $q->where('stock_balances.warehouse_id', $v))
            ->when($request->query('category_id'), fn ($q, $v) => $q->where('products.category_id', $v))
            ->when($request->query('search'), fn ($q, $s) => $q->where(
                fn ($w) => $w->where('products.name', 'like', "%{$s}%")->orWhere('products.sku', 'like', "%{$s}%")
            ))
            ->when(! $request->boolean('include_zero'), fn ($q) => $q->havingRaw('SUM(stock_balances.quantity) <> 0'))
            ->paginate(min((int) $request->query('per_page', 25), 100));

        $rows = $paginator->getCollection();
        $products = Product::with(['category:id,name', 'baseUnit:id,name'])->whereIn('id', $rows->pluck('product_id'))->get()->keyBy('id');
        $warehouses = Warehouse::whereIn('id', $rows->pluck('warehouse_id'))->get(['id', 'name'])->keyBy('id');
        $packs = $this->largestPacks($rows->pluck('product_id')->all());
        $levels = ReorderLevel::whereIn('product_id', $rows->pluck('product_id'))->get()->keyBy(fn ($r) => "{$r->warehouse_id}:{$r->product_id}");

        $paginator->setCollection($rows->map(function ($r) use ($products, $warehouses, $packs, $levels) {
            $p = $products[$r->product_id];
            $level = $levels["{$r->warehouse_id}:{$r->product_id}"]->min_quantity ?? null;

            return [
                'warehouse' => $warehouses[$r->warehouse_id],
                'product' => [
                    'id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'size_label' => $p->size_label,
                    'category' => $p->category?->name, 'base_unit' => $p->baseUnit?->name, 'pack' => $packs[$p->id] ?? null,
                ],
                'saleable' => (int) $r->saleable, 'damaged' => (int) $r->damaged, 'expired' => (int) $r->expired, 'total' => (int) $r->total,
                'reorder_level' => $level,
                'is_low' => $level !== null && (int) $r->saleable < $level,
            ];
        }));

        return response()->json($paginator);
    }

    /**
     * What to buy. For every product with a reorder level: stock now, how fast it has been selling from that warehouse over the
     * last 30 days, how many days it will last, and how much to order to get back above the level with `cover` days of selling on top.
     * Listed when stock is under the level or will run out within `within` days.
     */
    public function reorderSuggestions(Request $request): JsonResponse
    {
        $data = $request->validate(['cover' => ['nullable', 'integer', 'min:1', 'max:120'], 'within' => ['nullable', 'integer', 'min:1', 'max:60']]);
        $cover = (int) ($data['cover'] ?? 14);
        $within = (int) ($data['within'] ?? 7);
        $storeId = $this->access->requireStoreId();
        $since = now()->subDays(30)->startOfDay();

        $levels = ReorderLevel::query()->with(['product:id,name,sku,is_active', 'warehouse:id,name'])->get()->filter(fn ($l) => $l->product?->is_active);
        $stock = StockBalance::query()->where('status', 'saleable')->groupBy('warehouse_id', 'product_id')->selectRaw('warehouse_id, product_id, SUM(quantity) AS qty')->get()
            ->keyBy(fn ($r) => "{$r->warehouse_id}:{$r->product_id}");

        $sold = DB::table('sale_lines as l')->join('sales as s', 's.id', '=', 'l.sale_id')
            ->where('s.store_id', $storeId)->where('s.status', 'completed')->where('s.sold_at', '>=', $since)->where('s.client_ref', 'not like', 'import:%')
            ->groupBy('s.warehouse_id', 'l.product_id')->selectRaw('s.warehouse_id, l.product_id, SUM(l.quantity * l.factor) AS qty')->get()
            ->keyBy(fn ($r) => "{$r->warehouse_id}:{$r->product_id}");

        $packs = $this->largestPacks($levels->pluck('product_id')->unique()->all());

        $rows = $levels->map(function ($l) use ($stock, $sold, $packs, $cover) {
            $key = "{$l->warehouse_id}:{$l->product_id}";
            $onHand = (int) ($stock[$key]->qty ?? 0);
            $daily = round(((float) ($sold[$key]->qty ?? 0)) / 30, 2);
            $need = max((int) ceil($l->min_quantity + $daily * $cover - $onHand), 0);
            $pack = $packs[$l->product_id] ?? null;

            return [
                'product' => $l->product, 'warehouse' => $l->warehouse, 'saleable' => $onHand, 'min_quantity' => $l->min_quantity,
                'daily' => $daily, 'days_left' => $daily > 0 ? round($onHand / $daily, 1) : null, 'below_level' => $onHand < $l->min_quantity,
                'suggested' => $need, 'pack' => $pack, 'suggested_packs' => $pack ? (int) ceil($need / max($pack['factor'], 1)) : null,
            ];
        })->filter(fn ($r) => $r['below_level'] || ($r['days_left'] !== null && $r['days_left'] <= $within))
            ->sortBy(fn ($r) => $r['days_left'] ?? 9999)->values();

        return response()->json(['cover' => $cover, 'within' => $within, 'data' => $rows]);
    }

    /** Reorder levels that saleable stock has fallen below, including products with no stock at all. */
    public function lowStock(): JsonResponse
    {
        $saleable = StockBalance::query()->selectRaw('COALESCE(SUM(stock_balances.quantity), 0)')
            ->whereColumn('stock_balances.warehouse_id', 'reorder_levels.warehouse_id')
            ->whereColumn('stock_balances.product_id', 'reorder_levels.product_id')
            ->where('stock_balances.status', 'saleable');

        $rows = ReorderLevel::query()
            ->select('reorder_levels.*')->addSelect(['saleable' => $saleable])
            ->with(['product:id,name,sku', 'warehouse:id,name'])
            ->get()
            ->filter(fn ($r) => (int) $r->saleable < $r->min_quantity)
            ->map(fn ($r) => [
                'warehouse' => $r->warehouse, 'product' => $r->product,
                'saleable' => (int) $r->saleable, 'min_quantity' => $r->min_quantity,
                'shortfall' => $r->min_quantity - (int) $r->saleable,
            ])->values();

        return response()->json(['data' => $rows]);
    }

    public function movements(Request $request): JsonResponse
    {
        $rows = StockMovement::query()
            ->with(['product:id,name,sku', 'warehouse:id,name', 'user:id,name'])
            ->when($request->query('warehouse_id'), fn ($q, $v) => $q->where('warehouse_id', $v))
            ->when($request->query('product_id'), fn ($q, $v) => $q->where('product_id', $v))
            ->when($request->query('movement_type'), fn ($q, $v) => $q->where('movement_type', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('from'), fn ($q, $v) => $q->where('occurred_at', '>=', $v))
            ->when($request->query('to'), fn ($q, $v) => $q->where('occurred_at', '<=', $v.' 23:59:59'))
            ->when($request->query('search'), fn ($q, $s) => $q->where(
                fn ($w) => $w->where('reference', 'like', "%{$s}%")
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$s}%")->orWhere('sku', 'like', "%{$s}%"))
            ))
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json($rows);
    }

    /** Set (or clear, with null/0) the saleable-stock level that triggers a low-stock alert. */
    public function setReorderLevel(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();

        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('store_id', $storeId)],
            'min_quantity' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);

        $warehouse = Warehouse::findOrFail($data['warehouse_id']); // scoped: 404 outside your access
        $keys = ['warehouse_id' => $warehouse->id, 'product_id' => $data['product_id']];

        if (! $data['min_quantity']) {
            ReorderLevel::where($keys)->get()->each->delete();

            return response()->json(['min_quantity' => null]);
        }

        $level = ReorderLevel::updateOrCreate($keys, [
            'store_id' => $warehouse->store_id, 'branch_id' => $warehouse->branch_id, 'min_quantity' => $data['min_quantity'],
        ]);

        return response()->json($level);
    }

    /** Largest active pack per product, for showing "5 crates 3 bottles" instead of 63. */
    private function largestPacks(array $productIds): array
    {
        return ProductUnit::query()
            ->whereIn('product_id', $productIds)->where('is_active', true)->where('is_base', false)
            ->with('unit:id,name')->get()
            ->groupBy('product_id')
            ->map(fn ($units) => $units->sortByDesc('factor')->first())
            ->map(fn ($u) => ['name' => $u->unit->name, 'factor' => $u->factor])
            ->all();
    }
}
