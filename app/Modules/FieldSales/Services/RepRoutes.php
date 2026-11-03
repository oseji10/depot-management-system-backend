<?php

namespace App\Modules\FieldSales\Services;

use App\Modules\Sales\Models\CustomerGroup;
use Illuminate\Support\Facades\DB;

/** Which customers a salesperson normally deals with: the ones on the routes they were given. */
class RepRoutes
{
    /** @return list<int> group ids */
    public static function routeIds(int $userId): array
    {
        return DB::table('rep_routes')->where('user_id', $userId)->pluck('customer_group_id')->map(fn ($v) => (int) $v)->all();
    }

    /** Customer ids on the rep's routes, or null when they have no route (meaning: everyone). */
    public static function customerIds(int $userId): ?array
    {
        $routes = self::routeIds($userId);

        if ($routes === []) {
            return null;
        }

        return DB::table('customer_group_members')->whereIn('customer_group_id', $routes)->pluck('customer_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    /** @return \Illuminate\Support\Collection<int, array{id:int,name:string}> */
    public static function routesOf(int $userId)
    {
        return CustomerGroup::whereIn('id', self::routeIds($userId))->orderBy('name')->get(['id', 'name'])->map(fn ($g) => ['id' => $g->id, 'name' => $g->name]);
    }

    /** Replace the rep's routes. Only groups of kind "route" belonging to the same business are kept. */
    public static function assign(int $userId, array $groupIds): void
    {
        $valid = CustomerGroup::where('kind', 'route')->whereIn('id', $groupIds)->pluck('id')->all();

        DB::table('rep_routes')->where('user_id', $userId)->delete();

        foreach ($valid as $id) {
            DB::table('rep_routes')->insert(['user_id' => $userId, 'customer_group_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** A customer a salesperson adds joins that salesperson's routes, so they stay in the rep's list. */
    public static function addCustomer(int $userId, int $customerId): void
    {
        foreach (self::routeIds($userId) as $groupId) {
            DB::table('customer_group_members')->insertOrIgnore(['customer_group_id' => $groupId, 'customer_id' => $customerId, 'created_at' => now()]);
        }
    }
}
