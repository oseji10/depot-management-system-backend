<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Organization\Services\StoreProvisioner;
use App\Modules\Organization\Services\SubscriptionService;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/** A person who runs more than one business: list them, start another (within their plan), and switch. */
class BusinessSwitchController extends Controller
{
    public function __construct(private SubscriptionService $subscriptions, private StoreProvisioner $provisioner)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $mine = DB::table('user_stores as us')->join('stores as s', 's.id', '=', 'us.store_id')->where('us.user_id', $user->id)->orderBy('s.name')
            ->get(['s.id', 's.name', 'us.is_owner']);
        [$allowed, $owned] = $this->limits($user->id);

        return response()->json([
            'current' => $user->store_id, 'data' => $mine->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'is_owner' => (bool) $b->is_owner]),
            'owned' => $owned, 'allowed' => $allowed, 'can_create' => $owned > 0 && $owned < $allowed,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'branch_name' => ['nullable', 'string', 'max:80']]);
        [$allowed, $owned] = $this->limits($user->id);

        abort_unless($owned > 0, 403, 'Only a business owner can start another business.');
        abort_if($owned >= $allowed, 422, "Your plan allows {$allowed} business".($allowed === 1 ? '' : 'es').'. Upgrade your plan to add more.');

        [$store] = app(AccessScope::class)->withoutScope(fn () => $this->provisioner->provision([
            'name' => $data['name'], 'branch_name' => $data['branch_name'] ?? null, 'existing_owner' => $user,
        ]));

        return $this->switchTo($user, $store->id);
    }

    public function switch(Request $request, int $id): JsonResponse
    {
        return $this->switchTo($request->user(), $id);
    }

    /** Every business the person's own stores can have: the best of their plans, never below the platform default. */
    private function limits(int $userId): array
    {
        $stores = DB::table('user_stores')->where('user_id', $userId)->where('is_owner', true)->pluck('store_id');
        $default = $this->subscriptions->setting('default_max_businesses', 1);

        $best = DB::table('subscriptions as sub')->join('plans as p', 'p.id', '=', 'sub.plan_id')->whereIn('sub.store_id', $stores)->max('p.max_businesses');

        return [max($default, (int) $best), $stores->count()];
    }

    private function switchTo($user, int $storeId): JsonResponse
    {
        $m = DB::table('user_stores')->where('user_id', $user->id)->where('store_id', $storeId)->first();
        abort_unless($m, 403, 'You do not belong to that business.');
        abort_unless(DB::table('stores')->where('id', $storeId)->where('is_active', true)->exists(), 403, 'That business is suspended.');

        DB::table('users')->where('id', $user->id)->update(['store_id' => $storeId, 'branch_id' => $m->branch_id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($storeId);

        return response()->json(['ok' => true, 'store_id' => $storeId]);
    }

    /** Branches and warehouses to pick from. Read straight from the tables because the app-wide branch filter would otherwise hide the others. */
    public function scopes(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user->is_super_admin || $user->branch_id || ! $user->store_id, 403, 'Only business-wide users can switch branch.');

        return response()->json([
            'branches' => DB::table('branches')->where('store_id', $user->store_id)->where('is_active', true)->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'warehouses' => DB::table('warehouses')->where('store_id', $user->store_id)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'branch_id']),
        ]);
    }
}
