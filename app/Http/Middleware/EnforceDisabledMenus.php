<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Switch off menus" used to only hide the links. This also refuses the matching API calls, so a switched-off section cannot
 * be reached by typing the address or calling the API directly.
 *
 * Only endpoints that belong to a single menu are listed. Shared lookups (products, customers, sales, units, categories, accounts,
 * suppliers, price lists...) are used by other screens, such as the till, so they are never blocked.
 */
class EnforceDisabledMenus
{
    /** menu link => API paths (after /api/v1/) that exist only for that screen. */
    public const MAP = [
        '/pos' => ['pos'],
        '/customers/insights' => ['customers/insights', 'customer-groups'],
        '/customers/aging' => ['customers/aging'],
        '/returns' => ['returns'],
        '/field' => ['reps'],
        '/field/trips' => ['trips'],
        '/field/issues' => ['sync-issues'],
        '/vehicles' => ['vehicles', 'vehicle-documents', 'vehicle-services'],
        '/fuel' => ['fuel-stations', 'fuel-vouchers'],
        '/purchase-orders' => ['purchase-orders'],
        '/goods-receipts' => ['goods-receipts'],
        '/stock/reorder' => ['stock/reorder-suggestions'],
        '/stock/expiry' => ['stock/expiry'],
        '/stock/movements' => ['stock/movements'],
        '/stock-adjustments' => ['stock-adjustments'],
        '/stock-transfers' => ['stock-transfers'],
        '/stock-counts' => ['stock-counts'],
        '/containers' => ['containers'],
        '/rebates' => ['rebate-calculations', 'rebates'],
        '/rebate-agreements' => ['rebate-agreements'],
        '/employees' => ['employees'],
        '/payroll' => ['payroll-runs', 'payslips', 'payslip-lines', 'pay-items'],
        '/staff-charges' => ['staff-charges'],
        '/pos-terminals' => ['pos-terminals'],
        '/expenses' => ['expenses', 'expense-categories'],
        '/journals' => ['journals'],
        '/bank-reconciliation' => ['bank-statements'],
        '/fiscal-periods' => ['fiscal-periods'],
        '/reports' => ['reports'],
    ];

    /** Paths inside a blocked prefix that other screens still need (the till lists card machines). */
    private const ALWAYS_OPEN = ['pos-terminals/available'];

    public function handle(Request $request, Closure $next): Response
    {
        $off = $request->user()?->store?->settings['disabled_menus'] ?? [];

        if ($off) {
            $path = trim(preg_replace('#^api/v1/#', '', $request->path()), '/');

            if (! $this->isOpen($path)) {
                foreach ($off as $href) {
                    foreach (self::MAP[rtrim($href, '/') ?: '/'] ?? [] as $prefix) {
                        if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                            return response()->json(['message' => 'This part of the system is switched off for your business.'], 403);
                        }
                    }
                }
            }
        }

        return $next($request);
    }

    private function isOpen(string $path): bool
    {
        foreach (self::ALWAYS_OPEN as $open) {
            if ($path === $open || str_starts_with($path, $open.'/')) {
                return true;
            }
        }

        return false;
    }
}
