<?php

use App\Modules\Accounting\Services\ChartDefaults;
use App\Modules\Organization\Models\Store;
use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration {
    private const GRANTS = [
        'Owner' => ['containers.view', 'containers.manage'],
        'Administrator' => ['containers.view', 'containers.manage'],
        'Depot Manager' => ['containers.view', 'containers.manage'],
        'Accountant' => ['containers.view'],
        'Inventory Officer' => ['containers.view', 'containers.manage'],
        'Warehouse Officer' => ['containers.view', 'containers.manage'],
        'Sales Cashier' => ['containers.view', 'containers.manage'],
        'Auditor' => ['containers.view'],
    ];

    /** Existing stores get the deposits account and the container permissions. */
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

            app(ChartDefaults::class)->seed($store->id);   // adds any account the chart is missing
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Intentionally empty.
    }
};
