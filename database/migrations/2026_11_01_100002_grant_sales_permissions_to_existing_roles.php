<?php

use App\Modules\Organization\Models\Store;
use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration {
    /** Cashiers add customers at the till; the accountant records payments against customer balances. */
    private const GRANTS = [
        'Sales Cashier' => ['customers.manage', 'warehouses.view'],
        'Accountant' => ['sales.create', 'sales.refund', 'customers.manage'],
        'Depot Manager' => ['sales.view', 'sales.create', 'sales.discount', 'sales.refund', 'customers.view', 'customers.manage'],
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
