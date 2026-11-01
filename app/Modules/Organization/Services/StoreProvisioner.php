<?php

namespace App\Modules\Organization\Services;

use App\Models\User;
use App\Modules\Accounting\Services\ChartDefaults;
use App\Modules\Catalogue\Services\CatalogueDefaults;
use App\Modules\Expenses\Services\ExpenseDefaults;
use App\Modules\Organization\Models\Branch;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\Warehouse;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Creates a complete, usable tenant: store, head office branch, main warehouse, roles and an owner. */
class StoreProvisioner
{
    /** @return array{0: Store, 1: User} */
    public function provision(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $store = Store::create([
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($data['name']),
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
            ]);

            $branch = Branch::create([
                'store_id' => $store->id,
                'name' => $data['branch_name'] ?? 'Head Office',
                'code' => 'HQ',
                'is_head_office' => true,
            ]);

            Warehouse::create([
                'store_id' => $store->id,
                'branch_id' => $branch->id,
                'name' => 'Main Warehouse',
                'code' => 'WH-01',
                'is_default' => true,
            ]);

            $this->seedRoles($store);
            app(CatalogueDefaults::class)->seed($store);
            app(ChartDefaults::class)->seed($store->id);
            app(ExpenseDefaults::class)->seed($store->id);

            app(SubscriptionService::class)->startTrial($store->id); // every new business starts on a free trial

            // A person who already owns a business can start another: they keep their login and become its owner too.
            $owner = $data['existing_owner'] ?? User::create([
                'store_id' => $store->id,
                'branch_id' => null, // store level: sees every branch
                'name' => $data['owner_name'],
                'email' => $data['owner_email'],
                'password' => $data['owner_password'],
            ]);

            app(PermissionRegistrar::class)->setPermissionsTeamId($store->id);
            $owner->unsetRelation('roles')->unsetRelation('permissions');
            $owner->assignRole('Owner');

            DB::table('user_stores')->insertOrIgnore(['user_id' => $owner->id, 'store_id' => $store->id, 'branch_id' => null, 'is_owner' => true, 'created_at' => now(), 'updated_at' => now()]);

            if (empty($data['existing_owner'])) {
                app(NotificationEmailer::class)->welcome($store, $owner); // queued; a mail problem never fails sign-up
            }

            return [$store, $owner];
        });
    }

    public function seedRoles(Store $store): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($store->id);

        foreach (Permissions::roleTemplates() as $name => $permissions) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web', 'store_id' => $store->id]);
            $role->syncPermissions($permissions);
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'store';
        $slug = $base;
        $i = 2;

        while (Store::withoutGlobalScopes()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
