<?php

use App\Modules\Organization\Models\Store;
use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration {
    /** Existing stores get the new permissions, plus the Field Sales role that salesperson accounts use. */
    private const GRANTS = [
        'Owner' => ['trips.view', 'trips.manage'],
        'Administrator' => ['trips.view', 'trips.manage'],
        'Depot Manager' => ['trips.view', 'trips.manage'],
        'Inventory Officer' => ['trips.view', 'trips.manage'],
        'Warehouse Officer' => ['trips.view', 'trips.manage'],
        'Accountant' => ['trips.view'],
        'Auditor' => ['trips.view'],
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

            $role = Role::firstOrCreate(['name' => 'Field Sales', 'guard_name' => 'web', 'store_id' => $store->id]);
            $role->syncPermissions(Permissions::roleTemplates()['Field Sales']);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Intentionally empty.
    }
};
