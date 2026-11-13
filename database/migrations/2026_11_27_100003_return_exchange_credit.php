<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A return can be settled as an exchange: no money goes back, the value stays as credit on the customer's account. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sale_returns', 'exchange_credit')) {
            Schema::table('sale_returns', fn (Blueprint $t) => $t->unsignedBigInteger('exchange_credit')->default(0)->after('refunded'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sale_returns', 'exchange_credit')) {
            Schema::table('sale_returns', fn (Blueprint $t) => $t->dropColumn('exchange_credit'));
        }
    }
};
