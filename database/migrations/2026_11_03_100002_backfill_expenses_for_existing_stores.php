<?php

use App\Modules\Expenses\Services\ExpenseDefaults;
use App\Modules\Organization\Models\Store;
use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration {
    private const GRANTS = [
        'Owner' => ['expenses.view', 'expenses.create'],
        'Administrator' => ['expenses.view', 'expenses.create'],
        'Depot Manager' => ['expenses.view', 'expenses.create'],
        'Accountant' => ['expenses.view', 'expenses.create'],
        'Auditor' => ['expenses.view'],
    ];

    /** Existing stores get expense categories and permissions; the Field Sales role is brought up to date. */
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

            Role::where('store_id', $store->id)->where('name', 'Field Sales')->first()?->syncPermissions(Permissions::roleTemplates()['Field Sales']);

            app(ExpenseDefaults::class)->seed($store->id);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Intentionally empty.
    }
};
