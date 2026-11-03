<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Fuel is now counted in litres. The money is still tracked: a station has a usual price per litre, and every top-up and voucher keeps both.
        // Each step checks first, so the migration can be run again after a partial failure (MySQL cannot roll back column changes).
        if (! Schema::hasColumn('fuel_stations', 'price_per_litre')) {
            Schema::table('fuel_stations', fn (Blueprint $t) => $t->unsignedBigInteger('price_per_litre')->nullable()->after('phone')); // kobo
        }

        if (! Schema::hasColumn('fuel_station_entries', 'litres')) {
            Schema::table('fuel_station_entries', fn (Blueprint $t) => $t->decimal('litres', 12, 2)->nullable()->after('amount')); // signed, like amount
        }

        if (! Schema::hasColumn('fuel_vouchers', 'price_per_litre')) {
            Schema::table('fuel_vouchers', fn (Blueprint $t) => $t->unsignedBigInteger('price_per_litre')->nullable()->after('litres')); // kobo
        }

        if (! Schema::hasColumn('fuel_vouchers', 'void_reason')) {
            Schema::table('fuel_vouchers', fn (Blueprint $t) => $t->string('void_reason', 255)->nullable()->after('cancelled_at'));
        }

        if (! Schema::hasColumn('fuel_vouchers', 'voided_by')) {
            Schema::table('fuel_vouchers', fn (Blueprint $t) => $t->foreignId('voided_by')->nullable()->after('void_reason')->constrained('users')->nullOnDelete());
        }

        // A voucher now counts as used the moment it is issued. Vouchers still "out with the driver" before this change never
        // took anything off the station's balance, so they are voided (nothing to return) and can simply be issued again.
        DB::table('fuel_vouchers')->where('status', 'issued')->update([
            'status' => 'cancelled', 'cancelled_at' => now(), 'void_reason' => 'Issued before vouchers moved to litres. Please issue again.',
        ]);
    }

    public function down(): void
    {
        Schema::table('fuel_vouchers', function (Blueprint $t) {
            $t->dropConstrainedForeignId('voided_by');
            $t->dropColumn(['price_per_litre', 'void_reason']);
        });
        Schema::table('fuel_station_entries', fn (Blueprint $t) => $t->dropColumn('litres'));
        Schema::table('fuel_stations', fn (Blueprint $t) => $t->dropColumn('price_per_litre'));
    }
};
