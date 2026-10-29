<?php

use App\Modules\Catalogue\Services\CatalogueDefaults;
use App\Modules\Organization\Models\Store;
use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * One-off, for stores created before the catalogue existed: register the new permissions, grant them to the
 * standard roles (additively, so any customisation a store made is left alone) and create default catalogue data.
 * New stores get all of this from StoreProvisioner.
 */
return new class extends Migration {
    private const NEW = ['pricing.view', 'pricing.manage'];

    public function up(): void
    {
        // On a fresh database there are no stores yet, and Spatie's tables may not exist at this point.
        if (! Schema::hasTable('permissions') || ! Store::withoutGlobalScopes()->exists()) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permissions::all() as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $templates = Permissions::roleTemplates();

        foreach (Store::withoutGlobalScopes()->get() as $store) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($store->id);

            foreach ($templates as $roleName => $permissions) {
                $role = Role::where('store_id', $store->id)->where('name', $roleName)->first();

                if ($role && ($grant = array_values(array_intersect($permissions, self::NEW)))) {
                    $role->givePermissionTo($grant);
                }
            }

            app(CatalogueDefaults::class)->seed($store);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Intentionally empty: removing granted permissions or seeded defaults could destroy store data.
    }
};
