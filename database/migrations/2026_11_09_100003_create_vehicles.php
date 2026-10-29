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
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->string('reg_no', 30);
            $t->string('nickname')->nullable();
            $t->string('type', 16)->default('truck');          // truck | van | pickup | motorbike | other
            $t->string('make')->nullable();
            $t->string('model')->nullable();
            $t->unsignedSmallInteger('year')->nullable();
            $t->string('color', 30)->nullable();
            $t->unsignedInteger('capacity_crates')->nullable();
            $t->string('chassis_no', 40)->nullable();
            $t->string('engine_no', 40)->nullable();
            $t->string('fuel_type', 12)->default('petrol');    // petrol | diesel | gas | electric
            $t->unsignedInteger('odometer')->default(0);       // km
            $t->string('status', 14)->default('active');       // active | in_workshop | retired
            $t->foreignId('default_driver_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('notes')->nullable();
            $t->timestamps();
            $t->unique(['store_id', 'reg_no']);
        });

        Schema::create('vehicle_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 24);                            // insurance | roadworthiness | vehicle_license | proof_of_ownership | other
            $t->string('reference')->nullable();
            $t->date('issued_on')->nullable();
            $t->date('expires_on')->nullable();
            $t->string('notes')->nullable();
            $t->timestamps();
            $t->index(['vehicle_id', 'expires_on']);
        });

        Schema::create('vehicle_services', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $t->date('serviced_on');
            $t->string('kind', 16)->default('service');        // service | repair | tyres | accident | other
            $t->string('description');
            $t->string('vendor')->nullable();
            $t->unsignedInteger('odometer')->nullable();
            $t->unsignedBigInteger('cost')->default(0);        // kobo
            $t->unsignedBigInteger('expense_id')->nullable();  // set when the cost was booked as an expense
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });

        Schema::table('trips', function (Blueprint $t) {
            $t->foreignId('vehicle_id')->nullable()->after('van_warehouse_id')->constrained()->nullOnDelete();
        });

        Schema::table('fuel_vouchers', function (Blueprint $t) {
            $t->foreignId('vehicle_id')->nullable()->after('trip_id')->constrained()->nullOnDelete();
        });

        if (Schema::hasTable('permissions') && Store::withoutGlobalScopes()->exists()) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            foreach (Permissions::all() as $name) {
                Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            }

            $grants = [
                'Owner' => ['vehicles.view', 'vehicles.manage'], 'Administrator' => ['vehicles.view', 'vehicles.manage'],
                'Depot Manager' => ['vehicles.view', 'vehicles.manage'], 'Accountant' => ['vehicles.view'],
                'Delivery Coordinator' => ['vehicles.view', 'vehicles.manage'], 'Auditor' => ['vehicles.view'],
            ];

            foreach (Store::withoutGlobalScopes()->get() as $store) {
                app(PermissionRegistrar::class)->setPermissionsTeamId($store->id);

                foreach ($grants as $roleName => $permissions) {
                    Role::where('store_id', $store->id)->where('name', $roleName)->first()?->givePermissionTo($permissions);
                }
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        Schema::table('fuel_vouchers', fn (Blueprint $t) => $t->dropConstrainedForeignId('vehicle_id'));
        Schema::table('trips', fn (Blueprint $t) => $t->dropConstrainedForeignId('vehicle_id'));
        Schema::dropIfExists('vehicle_services');
        Schema::dropIfExists('vehicle_documents');
        Schema::dropIfExists('vehicles');
    }
};
