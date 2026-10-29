<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Organization\Models\Branch;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\Warehouse;
use App\Modules\Inventory\Models\ReorderLevel;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleLine;
use App\Support\Tenancy\StoreScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();

        // Salespeople work from their van; the depot-wide figures are not theirs to see.
        $isRep = Warehouse::vanOf($user->id) !== null;

        return response()->json([
            'sales' => ! $isRep && $user->can('sales.view') ? $this->sales() : null,
            'low_stock' => ! $isRep && $user->can('inventory.view') ? $this->lowStockCount() : null,
            'on_road' => $user->can('trips.view') ? \App\Modules\FieldSales\Models\Trip::where('status', 'open')->count() : null,
            'sync_issues' => $user->can('trips.view') ? \App\Modules\FieldSales\Models\SyncIssue::where('status', 'open')->count() : null,
            'stores' => $request->user()->is_super_admin
                ? Store::withoutGlobalScope(StoreScope::class)->where('is_active', true)->count()
                : null,
            'branches' => Branch::where('is_active', true)->count(),
            'warehouses' => Warehouse::where('is_active', true)->where('type', 'standard')->count(),
            'users' => User::visibleToActor()->where('users.is_active', true)->count(),
        ]);
    }

    /** Today, this month, what is owed, the last 14 days and the best sellers. All branch-scoped for branch users. */
    private function sales(): array
    {
        $today = now()->toDateString();
        $month = now()->startOfMonth()->toDateString();
        $done = fn () => Sale::where('status', 'completed');

        $daily = $done()->where('sold_at', '>=', now()->subDays(13)->startOfDay())
            ->selectRaw('DATE(sold_at) AS day, SUM(total) AS total, COUNT(*) AS orders')->groupBy('day')->orderBy('day')->get()->keyBy('day');

        $series = collect(range(13, 0))->map(function ($ago) use ($daily) {
            $day = now()->subDays($ago)->toDateString();

            return ['date' => $day, 'total' => (int) ($daily[$day]->total ?? 0), 'orders' => (int) ($daily[$day]->orders ?? 0)];
        })->values();

        $top = SaleLine::query()
            ->join('sales', 'sales.id', '=', 'sale_lines.sale_id')->join('products', 'products.id', '=', 'sale_lines.product_id')
            ->where('sales.status', 'completed')->where('sales.sold_at', '>=', $month)
            ->when(app(\App\Support\Tenancy\AccessScope::class)->branchId(), fn ($q, $b) => $q->where('sales.branch_id', $b))
            ->when(app(\App\Support\Tenancy\AccessScope::class)->storeId(), fn ($q, $st) => $q->where('sales.store_id', $st))
            ->groupBy('sale_lines.product_id', 'products.name')
            ->selectRaw('sale_lines.product_id, products.name, SUM(sale_lines.quantity * sale_lines.factor) AS units, SUM(sale_lines.line_total) AS revenue')
            ->orderByDesc('revenue')->limit(5)->get()
            ->map(fn ($r) => ['product_id' => $r->product_id, 'name' => $r->name, 'units' => (int) $r->units, 'revenue' => (int) $r->revenue]);

        return [
            'today' => ['total' => (int) $done()->where('sold_at', '>=', $today)->sum('total'), 'orders' => $done()->where('sold_at', '>=', $today)->count()],
            'month' => ['total' => (int) $done()->where('sold_at', '>=', $month)->sum('total'), 'orders' => $done()->where('sold_at', '>=', $month)->count()],
            'outstanding' => (int) $done()->sum('balance_due'),
            'unpaid_orders' => $done()->where('balance_due', '>', 0)->count(),
            'daily' => $series,
            'top_products' => $top,
        ];
    }

    private function lowStockCount(): int
    {
        $saleable = StockBalance::query()->selectRaw('COALESCE(SUM(stock_balances.quantity), 0)')
            ->whereColumn('stock_balances.warehouse_id', 'reorder_levels.warehouse_id')
            ->whereColumn('stock_balances.product_id', 'reorder_levels.product_id')
            ->where('stock_balances.status', 'saleable');

        return ReorderLevel::query()->addSelect(['saleable' => $saleable])->get()->filter(fn ($r) => (int) $r->saleable < $r->min_quantity)->count();
    }
}
