<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Expenses\Models\Expense;
use App\Modules\FieldSales\Models\SyncIssue;
use App\Modules\FieldSales\Models\Trip;
use App\Modules\Fleet\Models\FuelVoucher;
use App\Modules\Fleet\Models\Imprest;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\Fleet\Models\VehicleDocument;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\StaffCharge;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleLine;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * One page that summarises the whole business for a date range. Each section appears only if the person may see it,
 * and branch users only ever see their own branch (the models scope themselves).
 */
class OverviewController extends Controller
{
    public function __construct(private AccessScope $access)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);
        $user = $request->user();
        $storeId = $this->access->requireStoreId();
        $from = $data['from'];
        $to = $data['to'];
        $storeLevel = ! $this->access->isBranchRestricted();

        return response()->json([
            'period' => ['from' => $from, 'to' => $to],
            'sales' => $user->can('sales.view') ? $this->sales($from, $to) : null,
            'expenses' => $user->can('expenses.view') ? $this->expenses($from, $to) : null,
            'stock' => $user->can('inventory.view') ? $this->stock() : null,
            'accounts' => $storeLevel && $user->can('reports.view') ? $this->accounts($storeId, $from, $to) : null,
            'field' => $user->can('trips.view') ? $this->field($from, $to) : null,
            'people' => $storeLevel && $user->can('employees.view') ? $this->people($user->can('payroll.view'), $user->can('staff-charges.view')) : null,
            'fleet' => $user->can('vehicles.view') ? $this->fleet($user->can('fuel.view')) : null,
        ]);
    }

    private function sales(string $from, string $to): array
    {
        $done = fn () => Sale::where('status', 'completed')->whereBetween('sold_at', ["{$from} 00:00:00", "{$to} 23:59:59"]);

        $total = (int) $done()->sum('total');
        $orders = $done()->count();

        $daily = $done()->selectRaw('DATE(sold_at) AS day, SUM(total) AS total, COUNT(*) AS orders')->groupBy('day')->orderBy('day')->get()
            ->map(fn ($r) => ['date' => $r->day, 'total' => (int) $r->total, 'orders' => (int) $r->orders])->values();

        $methods = DB::table('sale_payments')->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->where('sales.status', 'completed')->whereBetween('sales.sold_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->when($this->access->storeId(), fn ($q, $s) => $q->where('sales.store_id', $s))
            ->when($this->access->branchId(), fn ($q, $b) => $q->where('sales.branch_id', $b))
            ->groupBy('sale_payments.method')->selectRaw('sale_payments.method, SUM(sale_payments.amount) AS amount')->pluck('amount', 'method')
            ->map(fn ($v) => (int) $v);

        $lines = fn () => SaleLine::query()->join('sales', 'sales.id', '=', 'sale_lines.sale_id')->join('products', 'products.id', '=', 'sale_lines.product_id')
            ->where('sales.status', 'completed')->whereBetween('sales.sold_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->when($this->access->storeId(), fn ($q, $s) => $q->where('sales.store_id', $s))
            ->when($this->access->branchId(), fn ($q, $b) => $q->where('sales.branch_id', $b));

        $top = $lines()->groupBy('sale_lines.product_id', 'products.name')
            ->selectRaw('products.name, SUM(sale_lines.quantity * sale_lines.factor) AS units, SUM(sale_lines.line_total) AS revenue')
            ->orderByDesc('revenue')->limit(10)->get()->map(fn ($r) => ['name' => $r->name, 'units' => (int) $r->units, 'revenue' => (int) $r->revenue]);

        $byBranch = DB::table('sales')->join('branches', 'branches.id', '=', 'sales.branch_id')
            ->where('sales.status', 'completed')->whereBetween('sales.sold_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->when($this->access->storeId(), fn ($q, $s) => $q->where('sales.store_id', $s))
            ->when($this->access->branchId(), fn ($q, $b) => $q->where('sales.branch_id', $b))
            ->groupBy('branches.name')->selectRaw('branches.name, SUM(sales.total) AS total, COUNT(*) AS orders')->orderByDesc('total')->get()
            ->map(fn ($r) => ['name' => $r->name, 'total' => (int) $r->total, 'orders' => (int) $r->orders]);

        $topCustomers = $done()->whereNotNull('customer_id')->with('customer:id,name')->get()->groupBy('customer_id')
            ->map(fn ($g) => ['name' => $g->first()->customer?->name, 'total' => (int) $g->sum('total'), 'orders' => $g->count()])
            ->sortByDesc('total')->take(5)->values();

        return [
            'total' => $total, 'orders' => $orders, 'average_order' => $orders ? intdiv($total, $orders) : 0,
            'collected' => (int) $methods->sum(), 'voided' => Sale::where('status', 'void')->whereBetween('sold_at', ["{$from} 00:00:00", "{$to} 23:59:59"])->count(),
            'owed_now' => (int) Sale::where('status', 'completed')->sum('balance_due'),
            'customers_owing' => Sale::where('status', 'completed')->where('balance_due', '>', 0)->distinct('customer_id')->count('customer_id'),
            'customers' => Customer::count(),
            'by_method' => $methods, 'daily' => $daily, 'top_products' => $top, 'by_branch' => $byBranch, 'top_customers' => $topCustomers,
        ];
    }

    private function expenses(string $from, string $to): array
    {
        $rows = Expense::query()->where('expenses.status', 'posted')->whereBetween('expenses.expense_date', [$from, $to])
            ->join('expense_categories', 'expense_categories.id', '=', 'expenses.category_id')
            ->groupBy('expense_categories.name')->selectRaw('expense_categories.name, SUM(expenses.amount) AS amount')->orderByDesc('amount')->get()
            ->map(fn ($r) => ['name' => $r->name, 'amount' => (int) $r->amount]);

        return ['total' => (int) $rows->sum('amount'), 'by_category' => $rows];
    }

    private function stock(): array
    {
        $today = now()->toDateString();
        $soon = now()->addDays(30)->toDateString();

        return [
            'saleable_units' => (int) StockBalance::where('status', 'saleable')->sum('quantity'),
            'damaged_units' => (int) StockBalance::where('status', 'damaged')->sum('quantity'),
            'expired_units' => (int) StockBatch::where('quantity', '>', 0)->where('expiry_date', '<', $today)->sum('quantity'),
            'expiring_units' => (int) StockBatch::where('quantity', '>', 0)->whereBetween('expiry_date', [$today, $soon])->sum('quantity'),
            'products_in_stock' => StockBalance::where('status', 'saleable')->where('quantity', '>', 0)->distinct('product_id')->count('product_id'),
        ];
    }

    /** Profit and loss for the period and where the money sits, straight from posted journals. */
    private function accounts(int $storeId, string $from, string $to): array
    {
        $lines = fn () => DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_lines.store_id', $storeId)->where('journal_entries.status', 'posted');

        $period = $lines()->whereBetween('journal_entries.entry_date', [$from, $to])->groupBy('accounts.type')
            ->selectRaw('accounts.type, SUM(journal_lines.debit) AS dr, SUM(journal_lines.credit) AS cr')->get()->keyBy('type');

        $net = fn (string $type, bool $creditNormal) => (int) (($creditNormal ? 1 : -1) * (($period[$type]->cr ?? 0) - ($period[$type]->dr ?? 0)));

        $revenue = $net('revenue', true) + $net('other_income', true);
        $cogs = -$net('cogs', true);
        $expense = -$net('expense', true);

        $balance = fn (string $role) => (int) ($lines()->where('accounts.role', $role)->selectRaw('SUM(journal_lines.debit) - SUM(journal_lines.credit) AS b')->value('b') ?? 0);

        return [
            'revenue' => $revenue, 'cogs' => $cogs, 'gross_profit' => $revenue - $cogs, 'expenses' => $expense, 'net_profit' => $revenue - $cogs - $expense,
            'cash' => $balance('cash'), 'bank' => $balance('bank'), 'receivables' => $balance('receivables'),
            'payables' => -$balance('payables'), 'container_deposits' => -$balance('container_deposits'),
            'imprest_out' => $balance('imprest'), 'fuel_prepaid' => $balance('fuel_prepaid'), 'staff_owe' => $balance('staff_receivable'),
        ];
    }

    private function field(string $from, string $to): array
    {
        $trips = Trip::whereBetween('opened_at', ["{$from} 00:00:00", "{$to} 23:59:59"]);

        return [
            'trips' => (clone $trips)->count(), 'on_road' => Trip::where('status', 'open')->count(),
            'cash_variance' => (int) (clone $trips)->where('status', 'closed')->sum('cash_variance'),
            'sync_issues' => SyncIssue::where('status', 'open')->count(),
        ];
    }

    private function people(bool $payroll, bool $charges): array
    {
        $last = $payroll ? PayrollRun::where('status', '!=', 'void')->orderByDesc('period')->orderByDesc('id')->first() : null;

        return [
            'employees' => Employee::where('status', 'active')->count(),
            'monthly_basic' => (int) Employee::where('status', 'active')->sum('basic_salary'),
            'last_payroll' => $last ? ['period' => $last->period, 'status' => $last->status, 'net' => $last->total_net] : null,
            'charges_pending' => $charges ? StaffCharge::where('status', 'pending')->count() : null,
            'charges_outstanding' => $charges ? (int) StaffCharge::where('status', 'approved')->selectRaw('SUM(amount - recovered) AS due')->value('due') : null,
        ];
    }

    private function fleet(bool $fuel): array
    {
        $today = now()->toDateString();
        $soon = now()->addDays(30)->toDateString();
        $ids = Vehicle::pluck('id');

        return [
            'vehicles' => Vehicle::count(), 'available' => Vehicle::where('status', 'active')->count(), 'in_workshop' => Vehicle::where('status', 'in_workshop')->count(),
            'docs_expired' => VehicleDocument::whereIn('vehicle_id', $ids)->whereNotNull('expires_on')->where('expires_on', '<', $today)->count(),
            'docs_soon' => VehicleDocument::whereIn('vehicle_id', $ids)->whereBetween('expires_on', [$today, $soon])->count(),
            'imprest_issued' => (int) Imprest::where('status', 'issued')->sum('amount'),
            'fuel_vouchers_out' => $fuel ? (int) FuelVoucher::where('status', 'issued')->sum('amount') : null,
        ];
    }
}
