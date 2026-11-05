<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /** A card terminal given to a salesperson for good stays with them between trips. */
    public function up(): void
    {
        if (Schema::hasTable('pos_terminals') && ! Schema::hasColumn('pos_terminals', 'keep_with_rep')) {
            Schema::table('pos_terminals', fn (Blueprint $t) => $t->boolean('keep_with_rep')->default(false)->after('assigned_user_id'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pos_terminals', 'keep_with_rep')) {
            Schema::table('pos_terminals', fn (Blueprint $t) => $t->dropColumn('keep_with_rep'));
        }
    }
};
