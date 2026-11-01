<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\AccessScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A store-level user who picked a warehouse in the switcher sees lists for that warehouse only. This fills in `warehouse_id`
 * on the list screens that can filter by it, unless the screen asked for a particular one itself.
 */
class DefaultToChosenWarehouse
{
    private const LISTS = ['sales', 'sales/summary', 'stock', 'stock/movements', 'stock/expiry', 'stock-adjustments', 'goods-receipts'];

    public function __construct(private AccessScope $access)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && ! $request->query->has('warehouse_id') && ($id = $this->access->warehouseId())) {
            $path = trim(preg_replace('#^api/v1/#', '', $request->path()), '/');

            if (in_array($path, self::LISTS, true)) {
                $request->query->set('warehouse_id', (string) $id);
            }
        }

        return $next($request);
    }
}
