<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // A target can now cover every product at once (product_id empty), counted as the value sold rather than a quantity.
        foreach (['product_id', 'unit_id'] as $col) {
            try {
                Schema::table('sales_targets', fn (Blueprint $t) => $t->dropForeign([$col]));
            } catch (\Throwable) {
                // already dropped on a previous, partly-run attempt
            }
        }

        Schema::table('sales_targets', function (Blueprint $t) {
            $t->unsignedBigInteger('product_id')->nullable()->change();
            $t->unsignedBigInteger('unit_id')->nullable()->change();
        });

        // The reward is a flat amount, a percentage of the person's basic salary, or a percentage of a fixed amount.
        if (! Schema::hasColumn('sales_targets', 'reward_type')) {
            Schema::table('sales_targets', function (Blueprint $t) {
                $t->string('reward_type', 16)->default('flat')->after('target_base');       // flat | salary_percent | fixed_percent
                $t->decimal('reward_percent', 6, 2)->default(0)->after('reward_type');
                $t->unsignedBigInteger('reward_base')->default(0)->after('reward_percent'); // kobo, the amount a fixed_percent is taken of
            });
        }
    }

    public function down(): void
    {
        Schema::table('sales_targets', function (Blueprint $t) {
            $t->dropColumn(['reward_type', 'reward_percent', 'reward_base']);
        });
    }
};
