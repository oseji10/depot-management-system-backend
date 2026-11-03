<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Where a bank transfer went: 'pos' (the card machine's settlement account) or 'account' (another account on the chart).
        Schema::table('sale_payments', function (Blueprint $t) {
            $t->string('transfer_to', 10)->nullable()->after('pos_terminal_id');
            $t->foreignId('account_id')->nullable()->after('transfer_to')->constrained('accounts')->nullOnDelete();
        });

        // The card machine the salesperson carries on this trip.
        Schema::table('trips', function (Blueprint $t) {
            $t->foreignId('pos_terminal_id')->nullable()->after('vehicle_id')->constrained('pos_terminals')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $t) {
            $t->dropConstrainedForeignId('pos_terminal_id');
        });
        Schema::table('sale_payments', function (Blueprint $t) {
            $t->dropConstrainedForeignId('account_id');
            $t->dropColumn('transfer_to');
        });
    }
};
