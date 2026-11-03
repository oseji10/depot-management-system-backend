<?php

use App\Modules\Organization\Models\Store;
use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration {
    private const GRANTS = [
        'Owner' => ['targets.view', 'targets.manage'],
        'Administrator' => ['targets.view', 'targets.manage'],
        'Depot Manager' => ['targets.view', 'targets.manage'],
        'Accountant' => ['targets.view'],
        'HR Officer' => ['targets.view'],
        'Auditor' => ['targets.view'],
    ];

    public function up(): void
    {
        // One row per salesperson, product and month: how much they should sell, and the reward for reaching it.
        Schema::create('sales_targets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->string('period', 7);                                   // YYYY-MM, the month the target is for
            $t->foreignId('user_id')->constrained()->cascadeOnDelete(); // the salesperson
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $t->unsignedInteger('quantity');                           // in that unit, e.g. 200 crates
            $t->unsignedInteger('factor');                             // base units in that unit, frozen when the target is set
            $t->unsignedBigInteger('target_base');                     // quantity x factor
            $t->unsignedBigInteger('reward_amount')->default(0);       // kobo paid for hitting 100%
            $t->timestamps();
            $t->unique(['store_id', 'period', 'user_id', 'product_id']);
            $t->index(['store_id', 'period']);
        });

        // How much of the reward is paid at each level of achievement, e.g. 100% -> all of it, 80% -> half.
        Schema::create('target_tiers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('min_percent');                   // achievement needed, e.g. 80
            $t->unsignedSmallInteger('payout_percent');                // share of the full reward paid, e.g. 50
            $t->timestamps();
            $t->unique(['store_id', 'min_percent']);
        });

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
        Schema::dropIfExists('target_tiers');
        Schema::dropIfExists('sales_targets');
    }
};
