<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalogue\Models\Product;
use App\Modules\Catalogue\Models\ProductCategory;
use App\Modules\Catalogue\Models\ProductUnit;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Rebates\Models\RebateCalculation;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Modules\Organization\Models\Branch;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleLine;
use App\Modules\Sales\Models\SalePayment;
use App\Modules\Sales\Models\SaleReturn;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One call that feeds the whole Analytics page: totals, a time series, and breakdowns by channel, payment method, product,
 * customer, branch, weekday and hour, for a date range and the equal-length period before it. Money is in kobo.
 * Branch users see their own branch only, through the usual scopes.
 */
class AnalyticsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->endOfDay();
        abort_if($from->diffInDays($to) > 800, 422, 'Pick a range of at most about two years.');

        $days = $from->diffInDays($to) + 1;
        $prevTo = $from->copy()->subSecond();
        $prevFrom = $prevTo->copy()->subDays($days - 1)->startOfDay();
        $branch = $data['branch_id'] ?? null;

        $cur = $this->period($from, $to, $branch);
        $prev = $this->period($prevFrom, $prevTo, $branch);
        $granularity = $days <= 62 ? 'day' : 'month';

        return response()->json([
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $days, 'granularity' => $granularity],
            'previous' => ['from' => $prevFrom->toDateString(), 'to' => $prevTo->toDateString(), 'totals' => $prev['totals'], 'series' => $this->series($prev, $prevFrom, $prevTo, $granularity)],
            'totals' => $cur['totals'],
            'series' => $this->series($cur, $from, $to, $granularity),
            'by_channel' => $this->group($cur['sales'], 'channel'),
            'by_payment_status' => $this->group($cur['sales'], 'payment_status'),
            'by_method' => $cur['payments']->groupBy('method')->map(fn ($g, $k) => ['key' => $k, 'amount' => (int) $g->sum('amount'), 'count' => $g->count()])->sortByDesc('amount')->values(),
            'by_weekday' => $this->weekdays($cur['sales']),
            'by_hour' => $this->hours($cur['sales']),
            'by_branch' => $this->branches($cur['sales'], $cur['expenses']),
            'products' => $this->products($cur['sales']),
            'customers' => $this->customers($cur['sales'], $from, $to),
            'expenses_by_category' => $this->expenseCategories($cur['expenses']),
            'basket' => $this->basket($cur['sales']),
            'receivables' => $this->receivables($branch),
            'missing_cost' => $this->missingCost($cur['sales']),
            'transactions' => $this->transactions($from, $to, $branch),
            'catalogue' => $this->catalogue($cur, $branch),
            'discounts' => $this->discounts($cur, $from, $to),
        ]);
    }

    private function period(Carbon $from, Carbon $to, ?int $branch): array
    {
        $sales = Sale::query()
            ->where('status', 'completed')->whereBetween('sold_at', [$from, $to])
            ->when($branch, fn ($q) => $q->where('branch_id', $branch))
            ->get(['id', 'branch_id', 'customer_id', 'channel', 'payment_status', 'sold_at', 'subtotal', 'discount_total', 'total', 'amount_paid', 'balance_due', 'cogs_total']);

        $payments = SalePayment::query()->whereNull('voided_at')->whereBetween('paid_at', [$from, $to])
            ->when($branch, fn ($q) => $q->where('branch_id', $branch))->get(['method', 'amount', 'paid_at']);

        $expenses = Expense::query()->where('status', 'posted')->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->when($branch, fn ($q) => $q->where('branch_id', $branch))->get(['branch_id', 'category_id', 'expense_date', 'amount']);

        $returns = SaleReturn::query()->whereBetween('returned_at', [$from, $to])
            ->when($branch, fn ($q) => $q->where('branch_id', $branch))->get(['total', 'returned_at']);

        $lines = $sales->isEmpty() ? collect() : SaleLine::query()->whereIn('sale_id', $sales->pluck('id'))->get(['sale_id', 'product_id', 'quantity', 'factor', 'list_price', 'unit_price', 'line_total', 'cost_total']);
        $newCustomers = Customer::whereBetween('created_at', [$from, $to])->get(['created_at']);
        $named = $sales->whereNotNull('customer_id');
        $buyerCount = $named->pluck('customer_id')->unique()->count();

        $revenue = (int) $sales->sum('total');
        $cogs = (int) $sales->sum('cogs_total');
        $count = $sales->count();

        return [
            'sales' => $sales, 'payments' => $payments, 'expenses' => $expenses, 'returns' => $returns, 'lines' => $lines, 'new_customers' => $newCustomers,
            'totals' => [
                'sales' => $revenue,
                'orders' => $count,
                'average_order' => $count ? intdiv($revenue, $count) : 0,
                'discount' => (int) $sales->sum('discount_total'),
                'collected' => (int) $payments->sum('amount'),
                'owed' => (int) $sales->sum('balance_due'),
                'cogs' => $cogs,
                'gross_profit' => $revenue - $cogs,
                'expenses' => (int) $expenses->sum('amount'),
                'net_profit' => $revenue - $cogs - (int) $expenses->sum('amount'),
                'returns' => (int) $returns->sum('total'),
                'return_count' => $returns->count(),
                'customers' => $buyerCount,
                'units' => (int) $lines->sum(fn ($l) => $l->quantity * $l->factor),
                'avg_spend' => $buyerCount ? intdiv((int) $named->sum('total'), $buyerCount) : 0,
                'new_customers' => $newCustomers->count(),
            ],
        ];
    }

    private function bucket($date, string $granularity): string
    {
        return $granularity === 'day' ? Carbon::parse($date)->format('Y-m-d') : Carbon::parse($date)->format('Y-m');
    }

    private function series(array $p, Carbon $from, Carbon $to, string $granularity): array
    {
        $keys = [];
        for ($d = $from->copy(); $d <= $to; $granularity === 'day' ? $d->addDay() : $d->addMonthNoOverflow()) {
            $keys[$this->bucket($d, $granularity)] = ['date' => $this->bucket($d, $granularity), 'sales' => 0, 'orders' => 0, 'discount' => 0, 'expenses' => 0, 'collected' => 0, 'gross_profit' => 0, 'returns' => 0, 'units' => 0, 'buyers' => 0, 'new_customers' => 0, 'avg_spend' => 0, '_b' => [], '_named' => 0];
        }

        foreach ($p['sales'] as $s) {
            $k = $this->bucket($s->sold_at, $granularity);
            if (isset($keys[$k])) {
                $keys[$k]['sales'] += $s->total; $keys[$k]['orders']++; $keys[$k]['discount'] += $s->discount_total; $keys[$k]['gross_profit'] += $s->total - $s->cogs_total;
            }
        }
        $saleBucket = [];
        foreach ($p['sales'] as $s) {
            $k = $this->bucket($s->sold_at, $granularity);
            $saleBucket[$s->id] = $k;
            if (isset($keys[$k]) && $s->customer_id) { $keys[$k]['_b'][$s->customer_id] = true; $keys[$k]['_named'] += $s->total; }
        }
        foreach ($p['lines'] as $l) {
            $k = $saleBucket[$l->sale_id] ?? null;
            if ($k !== null && isset($keys[$k])) { $keys[$k]['units'] += $l->quantity * $l->factor; }
        }
        foreach ($p['new_customers'] as $c) {
            $k = $this->bucket($c->created_at, $granularity);
            if (isset($keys[$k])) { $keys[$k]['new_customers']++; }
        }
        foreach ($p['expenses'] as $e) {
            $k = $this->bucket($e->expense_date, $granularity);
            if (isset($keys[$k])) { $keys[$k]['expenses'] += $e->amount; }
        }
        foreach ($p['payments'] as $x) {
            $k = $this->bucket($x->paid_at, $granularity);
            if (isset($keys[$k])) { $keys[$k]['collected'] += $x->amount; }
        }
        foreach ($p['returns'] as $r) {
            $k = $this->bucket($r->returned_at, $granularity);
            if (isset($keys[$k])) { $keys[$k]['returns'] += $r->total; }
        }

        foreach ($keys as &$row) {
            $row['buyers'] = count($row['_b']);
            $row['avg_spend'] = $row['buyers'] ? intdiv($row['_named'], $row['buyers']) : 0;
            unset($row['_b'], $row['_named']);
        }
        unset($row);

        return array_values($keys);
    }

    private function group($sales, string $field): array
    {
        return $sales->groupBy($field)->map(fn ($g, $k) => ['key' => (string) $k, 'amount' => (int) $g->sum('total'), 'count' => $g->count()])->sortByDesc('amount')->values()->all();
    }

    private function weekdays($sales): array
    {
        $out = collect(range(0, 6))->mapWithKeys(fn ($i) => [$i => ['day' => $i, 'amount' => 0, 'count' => 0]])->all();
        foreach ($sales as $s) { $d = (int) $s->sold_at->dayOfWeek; $out[$d]['amount'] += $s->total; $out[$d]['count']++; }

        return array_values($out);
    }

    private function hours($sales): array
    {
        $out = array_fill(0, 24, ['amount' => 0, 'count' => 0]);
        foreach ($sales as $s) { $h = (int) $s->sold_at->format('G'); $out[$h]['amount'] += $s->total; $out[$h]['count']++; }

        return collect($out)->map(fn ($v, $h) => ['hour' => $h, ...$v])->values()->all();
    }

    private function branches($sales, $expenses): array
    {
        $names = Branch::pluck('name', 'id');

        return $sales->groupBy('branch_id')->map(fn ($g, $id) => [
            'id' => (int) $id, 'name' => $names[$id] ?? 'Branch', 'amount' => (int) $g->sum('total'), 'count' => $g->count(),
            'expenses' => (int) $expenses->where('branch_id', $id)->sum('amount'),
        ])->sortByDesc('amount')->values()->all();
    }

    private function products($sales): array
    {
        if ($sales->isEmpty()) { return []; }

        $lines = SaleLine::query()->whereIn('sale_id', $sales->pluck('id'))->get(['product_id', 'quantity', 'factor', 'line_total', 'cost_total']);
        $names = Product::whereIn('id', $lines->pluck('product_id')->unique())->pluck('name', 'id');

        return $lines->groupBy('product_id')->map(fn ($g, $id) => [
            'id' => (int) $id, 'name' => $names[$id] ?? 'Product',
            'units' => (int) $g->sum(fn ($l) => $l->quantity * $l->factor),
            'revenue' => (int) $g->sum('line_total'), 'cost' => (int) $g->sum('cost_total'),
            'profit' => (int) $g->sum('line_total') - (int) $g->sum('cost_total'),
        ])->sortByDesc('revenue')->values()->take(25)->all();
    }

    private function customers($sales, Carbon $from, Carbon $to): array
    {
        $names = Customer::whereIn('id', $sales->pluck('customer_id')->filter()->unique())->pluck('name', 'id');
        $with = $sales->whereNotNull('customer_id');
        $walkIn = $sales->whereNull('customer_id');

        $firstEver = Sale::query()->where('status', 'completed')->whereIn('customer_id', $with->pluck('customer_id')->unique())->where('sold_at', '<', $from)->distinct()->pluck('customer_id');
        $buyers = $with->pluck('customer_id')->unique();

        return [
            'top' => $with->groupBy('customer_id')->map(fn ($g, $id) => ['id' => (int) $id, 'name' => $names[$id] ?? 'Customer', 'amount' => (int) $g->sum('total'), 'orders' => $g->count(), 'owes' => (int) $g->sum('balance_due')])->sortByDesc('amount')->values()->take(15)->all(),
            'top_orders' => $with->groupBy('customer_id')->map(fn ($g, $id) => ['id' => (int) $id, 'name' => $names[$id] ?? 'Customer', 'amount' => (int) $g->sum('total'), 'orders' => $g->count(), 'owes' => (int) $g->sum('balance_due')])->sortByDesc('orders')->values()->take(15)->all(),
            'on_file' => Customer::count(),
            'buyers' => $buyers->count(),
            'returning' => $buyers->intersect($firstEver)->count(),
            'new_buyers' => $buyers->diff($firstEver)->count(),
            'new_on_file' => Customer::whereBetween('created_at', [$from, $to])->count(),
            'walk_in_sales' => $walkIn->count(),
            'walk_in_amount' => (int) $walkIn->sum('total'),
        ];
    }

    private function expenseCategories($expenses): array
    {
        $names = ExpenseCategory::withTrashed()->pluck('name', 'id');

        return $expenses->groupBy('category_id')->map(fn ($g, $id) => ['name' => $names[$id] ?? 'Other', 'amount' => (int) $g->sum('amount'), 'count' => $g->count()])->sortByDesc('amount')->values()->all();
    }

    private function basket($sales): array
    {
        if ($sales->isEmpty()) { return ['items_per_sale' => 0, 'units_per_sale' => 0]; }

        $lines = SaleLine::query()->whereIn('sale_id', $sales->pluck('id'))->get(['sale_id', 'quantity', 'factor']);

        return ['items_per_sale' => round($lines->count() / $sales->count(), 1), 'units_per_sale' => round($lines->sum(fn ($l) => $l->quantity * $l->factor) / $sales->count(), 1)];
    }

    /** Money still owed to the depot on completed sales, whatever the date range. */
    private function receivables(?int $branch): array
    {
        $q = Sale::query()->where('status', 'completed')->where('balance_due', '>', 0)->when($branch, fn ($q) => $q->where('branch_id', $branch));

        return ['amount' => (int) $q->sum('balance_due'), 'sales' => (clone $q)->count(), 'customers' => (clone $q)->whereNotNull('customer_id')->distinct()->count('customer_id')];
    }

    /** True when sales exist but no cost was recorded, so profit figures would overstate. */
    private function missingCost($sales): bool
    {
        return $sales->isNotEmpty() && $sales->sum('cogs_total') === 0;
    }

    /** Payments received in the period, newest first (capped), for the transaction history table. */
    private function transactions(Carbon $from, Carbon $to, ?int $branch): array
    {
        $q = SalePayment::query()->whereBetween('paid_at', [$from, $to])->when($branch, fn ($q) => $q->where('branch_id', $branch));
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('paid_at')->limit(200)->get(['id', 'sale_id', 'method', 'amount', 'reference', 'paid_at', 'voided_at']);
        $sales = Sale::withoutGlobalScopes()->whereIn('id', $rows->pluck('sale_id')->unique())->get(['id', 'number', 'customer_id', 'channel'])->keyBy('id');
        $names = Customer::whereIn('id', $sales->pluck('customer_id')->filter()->unique())->pluck('name', 'id');

        return [
            'total' => $total,
            'rows' => $rows->map(fn ($p) => [
                'id' => $p->id, 'date' => $p->paid_at?->toIso8601String(), 'number' => $sales[$p->sale_id]->number ?? null,
                'customer' => ($sales[$p->sale_id]->customer_id ?? null) ? ($names[$sales[$p->sale_id]->customer_id] ?? 'Customer') : null,
                'amount' => (int) $p->amount, 'method' => $p->method, 'channel' => $sales[$p->sale_id]->channel ?? null,
                'status' => $p->voided_at ? 'voided' : 'completed', 'reference' => $p->reference,
            ])->values()->all(),
        ];
    }

    /** What is on the shelf today (stock is a snapshot, not tied to the date range) and how products moved in the period. */
    private function catalogue(array $cur, ?int $branch): array
    {
        $products = Product::query()->where('is_active', true)->get(['id', 'name', 'category_id']);
        $stock = StockBalance::query()->where('status', 'saleable')
            ->when($branch, fn ($q) => $q->whereHas('warehouse', fn ($w) => $w->where('branch_id', $branch)))
            ->get(['product_id', 'quantity'])->groupBy('product_id')->map(fn ($g) => (int) $g->sum('quantity'));

        // Cost per bottle/can estimated from what past sales recorded, since stock itself carries no cost.
        $cost = SaleLine::query()->where('cost_total', '>', 0)->get(['product_id', 'quantity', 'factor', 'cost_total'])->groupBy('product_id')
            ->map(function ($g) { $u = $g->sum(fn ($l) => $l->quantity * $l->factor); return $u > 0 ? $g->sum('cost_total') / $u : 0; });

        $value = 0; $valued = 0;
        foreach ($stock as $pid => $qty) {
            if ($qty > 0 && ($cost[$pid] ?? 0) > 0) { $value += $qty * $cost[$pid]; $valued++; }
        }

        $soldUnits = $cur['lines']->groupBy('product_id')->map(fn ($g) => (int) $g->sum(fn ($l) => $l->quantity * $l->factor));
        $catNames = ProductCategory::pluck('name', 'id');
        $byCat = $products->groupBy('category_id')->map(fn ($g, $cid) => [
            'name' => $catNames[$cid] ?? 'Uncategorised',
            'units' => (int) $g->sum(fn ($p) => $soldUnits[$p->id] ?? 0),
        ])->filter(fn ($c) => $c['units'] > 0)->sortByDesc('units')->values()->take(8)->all();

        $out = $products->filter(fn ($p) => ($stock[$p->id] ?? 0) <= 0);
        $slow = $products->filter(fn ($p) => ($stock[$p->id] ?? 0) > 0 && ! isset($soldUnits[$p->id]))
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'stock' => $stock[$p->id]])->sortByDesc('stock')->values()->take(10)->all();

        return [
            'total_products' => $products->count(),
            'stock_units' => (int) $stock->sum(),
            'stock_value' => (int) round($value),
            'stock_value_products' => $valued,
            'out_of_stock' => $out->count(),
            'out_of_stock_names' => $out->pluck('name')->take(10)->values()->all(),
            'categories' => ProductCategory::count(),
            'pack_sizes' => ProductUnit::count(),
            'top_categories' => $byCat,
            'slow_movers' => $slow,
        ];
    }

    /** Discounts and price promotions given in the period, plus rebates the depot earned from the manufacturer. */
    private function discounts(array $cur, Carbon $from, Carbon $to): array
    {
        $sales = $cur['sales'];
        $names = Product::whereIn('id', $cur['lines']->pluck('product_id')->unique())->pluck('name', 'id');
        $perProduct = $cur['lines']->groupBy('product_id')->map(function ($g, $id) use ($names) {
            return ['id' => (int) $id, 'name' => $names[$id] ?? 'Product', 'discount' => (int) $g->sum(fn ($l) => max(0, $l->list_price - $l->unit_price) * $l->quantity), 'units' => (int) $g->sum(fn ($l) => $l->quantity * $l->factor)];
        })->filter(fn ($r) => $r['discount'] > 0)->sortByDesc('discount')->values()->take(10)->all();

        $withDiscount = $sales->where('discount_total', '>', 0);
        $rebates = RebateCalculation::query()->whereNull('voided_at')->whereBetween('period_end', [$from->toDateString(), $to->toDateString()])->get(['status', 'total_rebate']);

        return [
            'sales_with_discount' => $withDiscount->count(),
            'share_of_sales' => $sales->count() ? round($withDiscount->count() / $sales->count() * 100) : 0,
            'average_discount' => $withDiscount->count() ? intdiv((int) $withDiscount->sum('discount_total'), $withDiscount->count()) : 0,
            'by_channel' => $sales->groupBy('channel')->map(fn ($g, $k) => ['key' => (string) $k, 'amount' => (int) $g->sum('discount_total'), 'count' => $g->where('discount_total', '>', 0)->count()])->filter(fn ($r) => $r['amount'] > 0)->sortByDesc('amount')->values()->all(),
            'top_products' => $perProduct,
            'rebates' => ['count' => $rebates->count(), 'amount' => (int) $rebates->sum('total_rebate'), 'by_status' => $rebates->groupBy('status')->map(fn ($g, $k) => ['key' => (string) $k, 'amount' => (int) $g->sum('total_rebate'), 'count' => $g->count()])->values()->all()],
        ];
    }
}
