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
        'Owner' => ['proformas.view', 'proformas.manage'],
        'Administrator' => ['proformas.view', 'proformas.manage'],
        'Depot Manager' => ['proformas.view', 'proformas.manage'],
        'Sales Cashier' => ['proformas.view', 'proformas.manage'],
        'Field Sales' => ['proformas.view', 'proformas.manage'],
        'Accountant' => ['proformas.view'],
        'Auditor' => ['proformas.view'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('proformas')) {
            // A price quotation sent to a customer before they buy. It does not touch stock or money until it is turned into a sale.
            Schema::create('proformas', function (Blueprint $t) {
                $t->id();
                $t->foreignId('store_id')->constrained()->cascadeOnDelete();
                $t->unsignedBigInteger('branch_id')->nullable();
                $t->string('number', 20);
                $t->string('status', 12)->default('draft');          // draft | sent | accepted | converted | cancelled
                $t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
                $t->string('customer_name', 150);                    // typed in, so a prospect who is not a customer yet can be quoted
                $t->string('customer_phone', 30)->nullable();
                $t->string('customer_email', 150)->nullable();
                $t->string('customer_address', 255)->nullable();
                $t->date('issued_on');
                $t->date('valid_until')->nullable();
                $t->unsignedBigInteger('subtotal')->default(0);      // kobo, at the prices quoted
                $t->unsignedBigInteger('discount')->default(0);      // kobo taken off the whole quote
                $t->unsignedBigInteger('delivery_fee')->default(0);  // kobo added for delivery
                $t->unsignedBigInteger('total')->default(0);
                $t->text('notes')->nullable();
                $t->text('terms')->nullable();
                $t->foreignId('converted_sale_id')->nullable()->constrained('sales')->nullOnDelete();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->softDeletes();
                $t->unique(['store_id', 'number']);
                $t->index(['store_id', 'status']);
            });
        }

        if (! Schema::hasTable('proforma_lines')) {
            Schema::create('proforma_lines', function (Blueprint $t) {
                $t->id();
                $t->foreignId('store_id')->constrained()->cascadeOnDelete();
                $t->foreignId('proforma_id')->constrained('proformas')->cascadeOnDelete();
                $t->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
                $t->unsignedBigInteger('unit_id')->nullable();
                $t->string('description', 200);                       // product name and unit as quoted
                $t->unsignedInteger('factor')->default(1);
                $t->unsignedInteger('quantity');
                $t->unsignedBigInteger('unit_price');                 // kobo per unit
                $t->unsignedBigInteger('list_price')->default(0);     // kobo per unit on the price list that day
                $t->unsignedBigInteger('line_total');
                $t->timestamps();
            });
        }

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
        Schema::dropIfExists('proforma_lines');
        Schema::dropIfExists('proformas');
    }
};
