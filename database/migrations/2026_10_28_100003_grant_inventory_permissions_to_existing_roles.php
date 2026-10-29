<?php

use App\Modules\Organization\Models\Store;
use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** One-off, additive: new stock permissions for stores that already exist. New stores use the templates. */
return new class extends Migration {
    private const GRANTS = [
        'Owner' => ['inventory.approve', 'inventory.transfer', 'inventory.count'],
        'Administrator' => ['inventory.approve', 'inventory.transfer', 'inventory.count'],
        'Depot Manager' => ['inventory.approve', 'inventory.transfer', 'inventory.count'],
        'Inventory Officer' => ['inventory.transfer', 'inventory.count'],
        'Warehouse Officer' => ['inventory.transfer', 'inventory.count'],
        'Accountant' => ['inventory.view'],
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
