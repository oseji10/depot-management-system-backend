<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalogue\Models\Product;
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

        $revenue = (int) $sales->sum('total');
        $cogs = (int) $sales->sum('cogs_total');
        $count = $sales->count();

        return [
            'sales' => $sales, 'payments' => $payments, 'expenses' => $expenses, 'returns' => $returns,
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
                'customers' => $sales->pluck('customer_id')->filter()->unique()->count(),
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
            $keys[$this->bucket($d, $granularity)] = ['date' => $this->bucket($d, $granularity), 'sales' => 0, 'orders' => 0, 'discount' => 0, 'expenses' => 0, 'collected' => 0, 'gross_profit' => 0, 'returns' => 0];
        }

        foreach ($p['sales'] as $s) {
            $k = $this->bucket($s->sold_at, $granularity);
            if (isset($keys[$k])) {
                $keys[$k]['sales'] += $s->total; $keys[$k]['orders']++; $keys[$k]['discount'] += $s->discount_total; $keys[$k]['gross_profit'] += $s->total - $s->cogs_total;
            }
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
}
