<?php

use App\Modules\Organization\Models\Store;
use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration {
    private const HR = ['employees.view', 'employees.manage', 'payroll.view', 'payroll.manage', 'payroll.approve', 'payroll.pay', 'staff-charges.view', 'staff-charges.manage', 'staff-charges.approve'];

    private const GRANTS = [
        'Owner' => self::HR,
        'Administrator' => self::HR,
        'Depot Manager' => self::HR,
        'Accountant' => self::HR,
        'HR Officer' => ['employees.view', 'employees.manage', 'payroll.view', 'payroll.manage', 'staff-charges.view', 'staff-charges.manage'],
        'Auditor' => ['employees.view', 'payroll.view', 'staff-charges.view'],
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
