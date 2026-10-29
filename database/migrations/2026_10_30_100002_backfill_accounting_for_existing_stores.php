<?php

use App\Modules\Accounting\Services\ChartDefaults;
use App\Modules\Organization\Models\Store;
use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Existing stores get the standard chart of accounts and the new permissions. Stock they already hold is carried
 * at zero value (its true cost was never recorded); new receipts are valued from the day accounting starts.
 */
return new class extends Migration {
    private const NEW = ['accounts.manage', 'journals.create', 'periods.close'];

    private const GRANTS = [
        'Owner' => self::NEW,
        'Administrator' => self::NEW,
        'Depot Manager' => self::NEW,
        'Accountant' => self::NEW,
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

            if (! DB::table('accounts')->where('store_id', $store->id)->exists()) {
                app(ChartDefaults::class)->seed($store->id);
            }

            // Carry current saleable stock at zero value so the cost records match the stock ledger from day one.
            $rows = DB::table('stock_balances')->where('store_id', $store->id)->where('status', 'saleable')
                ->selectRaw('product_id, SUM(quantity) AS qty')->groupBy('product_id')->having('qty', '>', 0)->get();

            foreach ($rows as $r) {
                DB::table('product_costs')->insertOrIgnore([
                    'store_id' => $store->id, 'product_id' => $r->product_id, 'quantity' => $r->qty, 'value' => 0,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Intentionally empty.
    }
};
