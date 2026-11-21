<?php

namespace App\Modules\Sales\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Everything in the daily sales report for one business and one day, worked out in the business's own time zone.
 * Plain queries filtered by store, so it runs the same from the scheduler (no signed-in user) as from the app.
 * Amounts are kobo.
 */
class DailySalesReport
{
    public function build(int $storeId, string $date): array
    {
        $store = DB::table('stores')->where('id', $storeId)->first();
        abort_unless($store, 404);

        $tz = $store->timezone ?: 'Africa/Lagos';
        $day = CarbonImmutable::parse($date, $tz)->startOfDay();

        $today = $this->totals($storeId, $day, $tz);
        $yesterday = $this->totals($storeId, $day->subDay(), $tz);
        $lastWeek = $this->totals($storeId, $day->subWeek(), $tz);
        [$from, $to] = $this->range($day, $tz);

        return [
            'store' => ['name' => $store->name, 'address' => $store->address, 'phone' => $store->phone, 'email' => $store->email, 'logo' => $this->logo($store)],
            'date' => $day->toDateString(),
            'date_label' => $day->format('l, j F Y'),
            'generated_at' => CarbonImmutable::now($tz)->format('j M Y, g:i A'),
            'today' => $today,
            'yesterday' => $yesterday,
            'last_week' => $lastWeek,
            'payments' => $this->payments($storeId, $from, $to, $day->subDay(), $tz),
            'origins' => $this->origins($storeId, $from, $to),
            'branches' => $this->branches($storeId, $from, $to),
            'staff' => $this->staff($storeId, $from, $to),
            'products' => $this->products($storeId, $from, $to),
            'customers' => $this->customers($storeId, $from, $to),
            'returns' => $this->returns($storeId, $from, $to),
            'expenses' => $this->expenses($storeId, $day),
            'voids' => $this->voids($storeId, $from, $to),
            'receivables' => (int) DB::table('sales')->where('store_id', $storeId)->where('status', 'completed')->sum('balance_due'),
            'low_stock' => $this->lowStock($storeId),
            'trend' => $this->trend($storeId, $day, $tz),
        ];
    }

    /** The day as a [from, to) range in the database's time zone. */
    private function range(CarbonImmutable $day, string $tz): array
    {
        $db = config('app.timezone', 'UTC');

        return [$day->setTimezone($db)->toDateTimeString(), $day->addDay()->setTimezone($db)->toDateTimeString()];
    }

    private function sales(int $storeId, string $from, string $to)
    {
        return DB::table('sales')->where('sales.store_id', $storeId)->where('sales.status', 'completed')
            ->where('sales.sold_at', '>=', $from)->where('sales.sold_at', '<', $to)
            ->when(Schema::hasColumn('sales', 'deleted_at'), fn ($q) => $q->whereNull('sales.deleted_at'));
    }

    /** The headline numbers for one day. */
    private function totals(int $storeId, CarbonImmutable $day, string $tz): array
    {
        [$from, $to] = $this->range($day, $tz);
        $deposits = Schema::hasColumn('sales', 'deposit_total') ? 'SUM(deposit_total)' : '0';

        $s = $this->sales($storeId, $from, $to)
            ->selectRaw("COUNT(*) AS n, COALESCE(SUM(total),0) AS total, COALESCE(SUM(discount_total),0) AS discount, COALESCE(SUM(cogs_total),0) AS cogs,
                COALESCE(SUM(balance_due),0) AS credit, COALESCE({$deposits},0) AS deposits, COUNT(DISTINCT customer_id) AS customers")->first();

        $items = (int) DB::table('sale_lines')->joinSub($this->sales($storeId, $from, $to)->select('sales.id'), 's', 's.id', '=', 'sale_lines.sale_id')
            ->sum(DB::raw('sale_lines.quantity * sale_lines.factor'));

        $collected = (int) DB::table('sale_payments')->where('store_id', $storeId)->whereNull('voided_at')
            ->where('paid_at', '>=', $from)->where('paid_at', '<', $to)->sum('amount');

        $total = (int) $s->total;

        return [
            'orders' => (int) $s->n, 'sales' => $total, 'discount' => (int) $s->discount, 'cogs' => (int) $s->cogs,
            'profit' => $total - (int) $s->cogs, 'margin' => $total > 0 ? round(($total - (int) $s->cogs) / $total * 100, 1) : 0.0,
            'average' => $s->n > 0 ? intdiv($total, (int) $s->n) : 0, 'items' => $items, 'credit' => (int) $s->credit,
            'deposits' => (int) $s->deposits, 'collected' => $collected, 'customers' => (int) $s->customers,
        ];
    }

    /** Money received today by method, with yesterday's for comparison, and how much of it paid off older sales. */
    private function payments(int $storeId, string $from, string $to, CarbonImmutable $prevDay, string $tz): array
    {
        [$pFrom, $pTo] = $this->range($prevDay, $tz);
        $base = fn ($a, $b) => DB::table('sale_payments')->where('sale_payments.store_id', $storeId)->whereNull('sale_payments.voided_at')
            ->where('sale_payments.paid_at', '>=', $a)->where('sale_payments.paid_at', '<', $b);

        $now = $base($from, $to)->selectRaw('method, SUM(amount) AS amount, COUNT(*) AS n')->groupBy('method')->get()->keyBy('method');
        $prev = $base($pFrom, $pTo)->selectRaw('method, SUM(amount) AS amount')->groupBy('method')->pluck('amount', 'method');

        $old = (int) $base($from, $to)->join('sales', 'sales.id', '=', 'sale_payments.sale_id')->where('sales.sold_at', '<', $from)->sum('sale_payments.amount');

        $labels = ['cash' => 'Cash', 'transfer' => 'Bank transfer', 'pos' => 'POS card'];
        $methods = collect(array_unique([...array_keys($labels), ...$now->keys()->all()]))->map(fn ($m) => [
            'label' => $labels[$m] ?? ucfirst($m), 'amount' => (int) ($now[$m]->amount ?? 0), 'count' => (int) ($now[$m]->n ?? 0), 'previous' => (int) ($prev[$m] ?? 0),
        ])->values()->all();

        return ['methods' => $methods, 'debt_collected' => $old];
    }

    /** Where the sales were made: each shop floor and each van. */
    private function origins(int $storeId, string $from, string $to): array
    {
        $rows = $this->sales($storeId, $from, $to)->join('warehouses as w', 'w.id', '=', 'sales.warehouse_id')
            ->selectRaw('w.name, w.type, COUNT(*) AS n, SUM(sales.total) AS total')->groupBy('w.id', 'w.name', 'w.type')->orderByDesc('total')->get();
        $sum = max(1, (int) $rows->sum('total'));

        return $rows->map(fn ($r) => ['name' => $r->name, 'van' => $r->type === 'van', 'orders' => (int) $r->n, 'total' => (int) $r->total, 'share' => round($r->total / $sum * 100, 1)])->all();
    }

    private function branches(int $storeId, string $from, string $to): array
    {
        return $this->sales($storeId, $from, $to)->join('branches as b', 'b.id', '=', 'sales.branch_id')
            ->selectRaw('b.name, COUNT(*) AS n, SUM(sales.total) AS total, SUM(sales.total - sales.cogs_total) AS profit')->groupBy('b.id', 'b.name')->orderByDesc('total')->get()
            ->map(fn ($r) => ['name' => $r->name, 'orders' => (int) $r->n, 'total' => (int) $r->total, 'profit' => (int) $r->profit])->all();
    }

    /** Who sold what: cashiers at the counter and van salesmen. */
    private function staff(int $storeId, string $from, string $to): array
    {
        return $this->sales($storeId, $from, $to)->join('users as u', 'u.id', '=', 'sales.created_by')
            ->selectRaw('u.name, COUNT(*) AS n, SUM(sales.total) AS total, SUM(sales.balance_due) AS credit')->groupBy('u.id', 'u.name')->orderByDesc('total')->limit(15)->get()
            ->map(fn ($r) => ['name' => $r->name, 'orders' => (int) $r->n, 'total' => (int) $r->total, 'credit' => (int) $r->credit])->all();
    }

    /** Every product sold today, by the variation it was sold in, biggest earner first. */
    private function products(int $storeId, string $from, string $to): array
    {
        $rows = DB::table('sale_lines as l')->joinSub($this->sales($storeId, $from, $to)->select('sales.id'), 's', 's.id', '=', 'l.sale_id')
            ->join('products as p', 'p.id', '=', 'l.product_id')->join('units as un', 'un.id', '=', 'l.unit_id')
            ->selectRaw('p.id, p.name, un.name AS unit, SUM(l.quantity) AS qty, SUM(l.quantity * l.factor) AS base, SUM(l.line_total) AS revenue, SUM(l.line_total - l.cost_total) AS profit')
            ->groupBy('p.id', 'p.name', 'un.name')->orderByDesc('revenue')->get();
        $sum = max(1, (int) $rows->sum('revenue'));

        return $rows->map(fn ($r) => [
            'name' => $r->name, 'unit' => $r->unit, 'qty' => (int) $r->qty, 'base' => (int) $r->base,
            'revenue' => (int) $r->revenue, 'profit' => (int) $r->profit, 'share' => round($r->revenue / $sum * 100, 1),
        ])->all();
    }

    private function customers(int $storeId, string $from, string $to): array
    {
        $buyers = $this->sales($storeId, $from, $to)->whereNotNull('customer_id')->distinct()->pluck('customer_id');
        $returning = $buyers->isEmpty() ? 0 : DB::table('sales')->where('store_id', $storeId)->where('status', 'completed')->whereIn('customer_id', $buyers)
            ->where('sold_at', '<', $from)->distinct()->count('customer_id');

        $top = $this->sales($storeId, $from, $to)->join('customers as c', 'c.id', '=', 'sales.customer_id')
            ->selectRaw('c.name, COUNT(*) AS n, SUM(sales.total) AS total, SUM(sales.balance_due) AS owing')->groupBy('c.id', 'c.name')->orderByDesc('total')->limit(10)->get()
            ->map(fn ($r) => ['name' => $r->name, 'orders' => (int) $r->n, 'total' => (int) $r->total, 'owing' => (int) $r->owing])->all();

        return [
            'buyers' => $buyers->count(),
            'new' => (int) DB::table('customers')->where('store_id', $storeId)->where('created_at', '>=', $from)->where('created_at', '<', $to)->count(),
            'returning' => $returning,
            'walk_in' => (int) $this->sales($storeId, $from, $to)->whereNull('customer_id')->count(),
            'top' => $top,
        ];
    }

    private function returns(int $storeId, string $from, string $to): array
    {
        if (! Schema::hasTable('sale_returns')) {
            return ['count' => 0, 'total' => 0, 'refunded' => 0];
        }

        $r = DB::table('sale_returns')->where('store_id', $storeId)->where('returned_at', '>=', $from)->where('returned_at', '<', $to)
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(total),0) AS total, COALESCE(SUM(refunded),0) AS refunded')->first();

        return ['count' => (int) $r->n, 'total' => (int) $r->total, 'refunded' => (int) $r->refunded];
    }

    private function expenses(int $storeId, CarbonImmutable $day): array
    {
        if (! Schema::hasTable('expenses')) {
            return ['total' => 0, 'cash' => 0, 'items' => []];
        }

        $q = DB::table('expenses as e')->where('e.store_id', $storeId)->where('e.status', 'posted')->whereDate('e.expense_date', $day->toDateString())
            ->when(Schema::hasColumn('expenses', 'deleted_at'), fn ($q) => $q->whereNull('e.deleted_at'));

        $items = (clone $q)->join('expense_categories as c', 'c.id', '=', 'e.category_id')->selectRaw('c.name, SUM(e.amount) AS amount, COUNT(*) AS n')
            ->groupBy('c.id', 'c.name')->orderByDesc('amount')->get()->map(fn ($r) => ['name' => $r->name, 'amount' => (int) $r->amount, 'count' => (int) $r->n])->all();

        return ['total' => (int) (clone $q)->sum('e.amount'), 'cash' => (int) (clone $q)->where('e.method', 'cash')->sum('e.amount'), 'items' => $items];
    }

    private function voids(int $storeId, string $from, string $to): array
    {
        $r = DB::table('sales')->where('store_id', $storeId)->where('status', 'void')->where('sold_at', '>=', $from)->where('sold_at', '<', $to)
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(total),0) AS total')->first();

        return ['count' => (int) $r->n, 'total' => (int) $r->total];
    }

    /** Products at or below their reorder level right now. */
    private function lowStock(int $storeId): array
    {
        if (! Schema::hasTable('reorder_levels')) {
            return [];
        }

        return DB::table('reorder_levels as r')->where('r.store_id', $storeId)
            ->join('products as p', 'p.id', '=', 'r.product_id')->join('warehouses as w', 'w.id', '=', 'r.warehouse_id')
            ->leftJoin('stock_balances as b', fn ($j) => $j->on('b.product_id', '=', 'r.product_id')->on('b.warehouse_id', '=', 'r.warehouse_id')->where('b.status', '=', 'saleable'))
            ->selectRaw('p.name, w.name AS warehouse, r.min_quantity AS min, COALESCE(SUM(b.quantity),0) AS qty')
            ->groupBy('r.id', 'p.name', 'w.name', 'r.min_quantity')->havingRaw('COALESCE(SUM(b.quantity),0) <= r.min_quantity')
            ->orderBy('qty')->limit(15)->get()->map(fn ($r) => ['name' => $r->name, 'warehouse' => $r->warehouse, 'qty' => (int) $r->qty, 'min' => (int) $r->min])->all();
    }

    /** Sales for the 7 days ending on the report day, for the small chart. */
    private function trend(int $storeId, CarbonImmutable $day, string $tz): array
    {
        $out = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = $day->subDays($i);
            [$a, $b] = $this->range($d, $tz);
            $out[] = ['label' => $d->format('D'), 'date' => $d->format('j M'), 'total' => (int) $this->sales($storeId, $a, $b)->sum('total')];
        }

        return $out;
    }

    /** The logo as a data URI so the PDF needs no network. */
    private function logo(object $store): ?string
    {
        try {
            if ($store->logo_path && Storage::disk('local')->exists($store->logo_path)) {
                $mime = Storage::disk('local')->mimeType($store->logo_path) ?: 'image/png';

                return 'data:'.$mime.';base64,'.base64_encode(Storage::disk('local')->get($store->logo_path));
            }
        } catch (\Throwable) {
        }

        return null;
    }
}
