<?php

namespace App\Modules\Organization\Services;

use App\Modules\FieldSales\Models\SyncIssue;
use App\Modules\Fleet\Models\VehicleDocument;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\StaffCharge;
use App\Modules\Inventory\Models\ReorderLevel;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Sales\Models\Sale;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Works out what is worth telling the business about: sales milestones, things that need attention. Each event is stored
 * once (the dedupe key), so polling never repeats it. Runs from a head-office user's request, at most every two minutes.
 */
class NotificationService
{
    /** Naira amounts worth celebrating, smallest first. */
    private const DAY = [100_000, 250_000, 500_000, 1_000_000, 2_000_000, 5_000_000, 10_000_000, 20_000_000, 50_000_000, 100_000_000];
    private const MONTH = [1_000_000, 2_500_000, 5_000_000, 10_000_000, 25_000_000, 50_000_000, 100_000_000, 250_000_000, 500_000_000, 1_000_000_000];

    public function refresh(int $storeId): void
    {
        if (! Cache::add("notifications-refresh:{$storeId}", 1, 120)) {
            return;
        }

        $today = now()->toDateString();

        $this->milestones($storeId, $today);
        $this->attention($storeId, $today);
    }

    private function milestones(int $storeId, string $today): void
    {
        $done = fn () => Sale::where('status', 'completed');
        $day = (int) $done()->where('sold_at', '>=', $today)->sum('total') / 100;
        $month = (int) $done()->where('sold_at', '>=', now()->startOfMonth()->toDateString())->sum('total') / 100;

        foreach ([['day', $day, self::DAY, $today, 'today'], ['month', $month, self::MONTH, now()->format('Y-m'), 'this month']] as [$kind, $total, $steps, $period, $label]) {
            $crossed = collect($steps)->filter(fn ($s) => $total >= $s)->last();

            if ($crossed) {
                $this->add($storeId, [
                    'kind' => 'milestone', 'severity' => 'success', 'permission' => 'sales.view', 'url' => '/reports',
                    'title' => '₦'.number_format($crossed).' in sales '.$label.'!',
                    'body' => 'You have sold ₦'.number_format($total).' '.$label.'. Keep it going.',
                    'dedupe_key' => "sales-{$kind}:{$period}:{$crossed}",
                ]);
            }
        }
    }

    private function attention(int $storeId, string $today): void
    {
        $saleable = StockBalance::query()->selectRaw('COALESCE(SUM(stock_balances.quantity), 0)')
            ->whereColumn('stock_balances.warehouse_id', 'reorder_levels.warehouse_id')->whereColumn('stock_balances.product_id', 'reorder_levels.product_id')
            ->where('stock_balances.status', 'saleable');
        $low = ReorderLevel::query()->addSelect(['saleable' => $saleable])->get()->filter(fn ($r) => (int) $r->saleable < $r->min_quantity)->count();

        if ($low > 0) {
            $this->add($storeId, ['kind' => 'stock', 'severity' => 'warning', 'permission' => 'inventory.view', 'url' => '/stock/reorder', 'dedupe_key' => "lowstock:{$today}",
                'title' => "{$low} product".($low === 1 ? ' is' : 's are').' running low', 'body' => 'Stock is below the reorder level. Time to place an order.']);
        }

        $expired = (int) StockBatch::where('quantity', '>', 0)->where('expiry_date', '<', $today)->sum('quantity');
        $soon = (int) StockBatch::where('quantity', '>', 0)->whereBetween('expiry_date', [$today, now()->addDays(30)->toDateString()])->sum('quantity');

        if ($expired > 0 || $soon > 0) {
            $this->add($storeId, ['kind' => 'expiry', 'severity' => $expired > 0 ? 'alert' : 'warning', 'permission' => 'inventory.view', 'url' => '/stock/expiry', 'dedupe_key' => "expiry:{$today}",
                'title' => $expired > 0 ? number_format($expired).' units have expired' : number_format($soon).' units expire within 30 days',
                'body' => $expired > 0 ? 'Expired stock cannot be sold. Write it off or return it.' : 'Sell the oldest stock first.']);
        }

        $expiredDocs = VehicleDocument::whereNotNull('expires_on')->where('expires_on', '<', $today)->count();
        $soonDocs = VehicleDocument::whereBetween('expires_on', [$today, now()->addDays(30)->toDateString()])->count();

        if ($expiredDocs > 0 || $soonDocs > 0) {
            $this->add($storeId, ['kind' => 'fleet', 'severity' => $expiredDocs > 0 ? 'alert' : 'warning', 'permission' => 'vehicles.view', 'url' => '/vehicles', 'dedupe_key' => "vdocs:{$today}",
                'title' => $expiredDocs > 0 ? "{$expiredDocs} vehicle paper".($expiredDocs === 1 ? ' has' : 's have').' expired' : "{$soonDocs} vehicle paper".($soonDocs === 1 ? ' expires' : 's expire').' soon',
                'body' => 'Renew insurance, roadworthiness and licences before the trucks go out.']);
        }

        $issues = SyncIssue::where('status', 'open')->count();

        if ($issues > 0) {
            $this->add($storeId, ['kind' => 'sync', 'severity' => 'warning', 'permission' => 'trips.view', 'url' => '/field/issues', 'dedupe_key' => "sync:{$today}",
                'title' => "{$issues} offline sale".($issues === 1 ? '' : 's').' need attention', 'body' => 'A salesperson’s phone sent something the depot could not accept.']);
        }

        $pending = StaffCharge::where('status', 'pending')->count();

        if ($pending > 0) {
            $this->add($storeId, ['kind' => 'approval', 'severity' => 'info', 'permission' => 'staff-charges.approve', 'url' => '/staff-charges', 'dedupe_key' => "charges:{$pending}",
                'title' => "{$pending} staff charge".($pending === 1 ? '' : 's').' awaiting approval', 'body' => 'Approve them so they are taken from the next salary.']);
        }

        foreach (PayrollRun::where('status', 'draft')->get() as $run) {
            $this->add($storeId, ['kind' => 'payroll', 'severity' => 'info', 'permission' => 'payroll.approve', 'url' => "/payroll/view?id={$run->id}", 'dedupe_key' => "payroll-draft:{$run->id}",
                'title' => "Payroll for {$run->period} is waiting for approval", 'body' => 'Check the payslips, then approve the run.']);
        }
    }

    private function add(int $storeId, array $n): void
    {
        DB::table('app_notifications')->insertOrIgnore([...$n, 'store_id' => $storeId, 'created_at' => now(), 'updated_at' => now()]);
    }
}
