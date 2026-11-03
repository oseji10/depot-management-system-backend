<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // A "bank account" is an asset account on the chart that also carries the real bank's details,
        // so a transfer can be shown (and booked) against the account the money actually went into.
        Schema::table('accounts', function (Blueprint $t) {
            $t->boolean('is_bank')->default(false)->after('is_active');
            $t->string('bank_name', 80)->nullable()->after('is_bank');
            $t->string('account_number', 20)->nullable()->after('bank_name');
            $t->string('account_name', 120)->nullable()->after('account_number');
        });

        // The main Bank account, and any account a card machine already settles into, are bank accounts.
        DB::table('accounts')->where('role', 'bank')->update(['is_bank' => true]);

        $used = DB::table('pos_terminals')->whereNotNull('account_id')->pluck('account_id');
        if ($used->isNotEmpty()) {
            DB::table('accounts')->whereIn('id', $used)->where('type', 'asset')->update(['is_bank' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('accounts', fn (Blueprint $t) => $t->dropColumn(['is_bank', 'bank_name', 'account_number', 'account_name']));
    }
};
