<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Running money handed to a driver for a trip. Never taken out of the sales they collect.
        Schema::create('imprests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('trip_id')->constrained()->restrictOnDelete();
            $t->foreignId('driver_id')->constrained('users')->restrictOnDelete();
            $t->string('number', 30);
            $t->unsignedBigInteger('amount');                 // kobo
            $t->string('method', 12)->default('cash');        // where the money came from: cash | bank
            $t->string('status', 12)->default('issued');      // issued | void
            $t->string('note')->nullable();
            $t->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('voided_at')->nullable();
            $t->string('void_reason')->nullable();
            $t->timestamps();
            $t->unique(['store_id', 'number']);
            $t->index('trip_id');
        });

        Schema::table('trips', function (Blueprint $t) {
            $t->bigInteger('imprest_issued')->nullable();
            $t->bigInteger('imprest_spent')->nullable();
            $t->bigInteger('imprest_returned')->nullable();      // cash the driver handed back at check-in
            $t->bigInteger('imprest_reimbursed')->nullable();    // paid to the driver because they spent more than they were given
            $t->bigInteger('imprest_unreturned')->nullable();    // left over but not handed back: still owed by the driver
        });

        Schema::create('fuel_stations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->string('name', 100);
            $t->string('location', 150)->nullable();
            $t->string('phone', 30)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['store_id', 'name']);
        });

        // The prepaid balance with a station: top-ups add, redeemed vouchers take away.
        Schema::create('fuel_station_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('fuel_station_id')->constrained()->restrictOnDelete();
            $t->string('type', 12);                           // top_up | redeemed | reversal
            $t->bigInteger('amount');                         // kobo, signed
            $t->date('entry_date');
            $t->string('method', 12)->nullable();             // top-ups: cash | bank
            $t->string('reference')->nullable();
            $t->unsignedBigInteger('fuel_voucher_id')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['fuel_station_id', 'entry_date']);
        });

        Schema::create('fuel_vouchers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('fuel_station_id')->constrained()->restrictOnDelete();
            $t->foreignId('driver_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('trip_id')->nullable()->constrained()->nullOnDelete();
            $t->string('number', 30);
            $t->string('code', 12);                           // what the driver shows at the pump
            $t->unsignedBigInteger('amount');                 // kobo, the most the driver may draw
            $t->unsignedBigInteger('amount_used')->nullable();
            $t->decimal('litres', 10, 2)->nullable();
            $t->string('vehicle', 40)->nullable();
            $t->string('status', 12)->default('issued');      // issued | redeemed | cancelled
            $t->string('note')->nullable();
            $t->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('redeemed_at')->nullable();
            $t->foreignId('redeemed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamps();
            $t->unique(['store_id', 'number']);
            $t->unique(['store_id', 'code']);
            $t->index(['fuel_station_id', 'status']);
        });

        // Accounts for the new postings, added to every existing depot.
        foreach (DB::table('stores')->pluck('id') as $storeId) {
            foreach ([['1400', 'Driver Imprest', 'asset', 'imprest'], ['1410', 'Fuel Prepayments', 'asset', 'fuel_prepaid']] as [$code, $name, $type, $role]) {
                DB::table('accounts')->insertOrIgnore([
                    'store_id' => $storeId, 'code' => $code, 'name' => $name, 'type' => $type, 'role' => $role,
                    'is_system' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            DB::table('accounts')->where('store_id', $storeId)->where('code', '6200')->whereNull('role')->update(['role' => 'fuel_expense', 'is_system' => true]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fuel_vouchers');
        Schema::dropIfExists('fuel_station_entries');
        Schema::dropIfExists('fuel_stations');
        Schema::table('trips', fn (Blueprint $t) => $t->dropColumn(['imprest_issued', 'imprest_spent', 'imprest_returned', 'imprest_reimbursed', 'imprest_unreturned']));
        Schema::dropIfExists('imprests');
    }
};
