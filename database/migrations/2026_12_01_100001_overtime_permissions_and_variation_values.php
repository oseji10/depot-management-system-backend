<?php

use App\Modules\Organization\Models\Store;
use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * 1. Overtime gets its own permissions (view, record, approve), so any role, e.g. Accountant, can be given it.
 *    Roles keep what they could do before: employees.view → overtime.view, employees.manage → overtime.manage,
 *    payroll.approve → overtime.approve. Accountant roles get all three.
 * 2. A variation can carry a value (free text, e.g. "Full crate (24)"), and each product's variation remembers its
 *    quantity as typed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('units', 'value')) {
            Schema::table('units', fn ($t) => $t->string('value', 60)->nullable()->after('name'));
        }
        if (! Schema::hasColumn('product_units', 'quantity')) {
            Schema::table('product_units', fn ($t) => $t->unsignedInteger('quantity')->nullable()->after('factor'));
        }

        if (! Schema::hasTable('permissions') || ! Store::withoutGlobalScopes()->exists()) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (Permissions::all() as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $map = ['employees.view' => 'overtime.view', 'employees.manage' => 'overtime.manage', 'payroll.approve' => 'overtime.approve'];

        foreach (Store::withoutGlobalScopes()->get() as $store) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($store->id);

            foreach (Role::where('store_id', $store->id)->get() as $role) {
                $give = [];
                foreach ($map as $had => $new) {
                    if ($role->hasPermissionTo($had)) {
                        $give[] = $new;
                    }
                }
                $isAccountant = (Schema::hasColumn('roles', 'system_key') && $role->system_key === 'accountant') || $role->name === 'Accountant';
                if ($isAccountant) {
                    $give = ['overtime.view', 'overtime.manage', 'overtime.approve'];
                }
                if ($give) {
                    $role->givePermissionTo($give);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (Schema::hasColumn('units', 'value')) {
            Schema::table('units', fn ($t) => $t->dropColumn('value'));
        }
        if (Schema::hasColumn('product_units', 'quantity')) {
            Schema::table('product_units', fn ($t) => $t->dropColumn('quantity'));
        }
    }
};
