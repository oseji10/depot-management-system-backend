<?php

use App\Modules\Organization\Models\Store;
use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** One-off and additive. Existing warehouse officers keep the "post" permission they already had. */
return new class extends Migration {
    private const ALL_NEW = ['purchasing.view', 'purchasing.manage', 'goods-receipts.record', 'goods-receipts.reverse'];

    private const GRANTS = [
        'Owner' => self::ALL_NEW,
        'Administrator' => self::ALL_NEW,
        'Depot Manager' => self::ALL_NEW,
        'Inventory Officer' => ['purchasing.view', 'purchasing.manage', 'goods-receipts.record'],
        'Warehouse Officer' => ['purchasing.view', 'goods-receipts.record'],
        'Accountant' => ['purchasing.view', 'goods-receipts.view'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Store::withoutGlobalScopes()->exists()) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permissions::all() as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (Store::withoutGlobalScopes()->get() as $store) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($store->id);

            foreach (self::GRANTS as $roleName => $permissions) {
                Role::where('store_id', $store->id)->where('name', $roleName)->first()?->givePermissionTo($permissions);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Intentionally empty.
    }
};
