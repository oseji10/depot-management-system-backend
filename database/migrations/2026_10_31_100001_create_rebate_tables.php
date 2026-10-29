<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // A failed earlier run leaves partial tables behind (MySQL DDL is not transactional); clear them so this can re-run.
        foreach (['rebate_calculation_sources', 'rebate_calculation_lines', 'rebate_calculations', 'rebate_tiers', 'rebate_clusters', 'rebate_agreements'] as $table) {
            Schema::dropIfExists($table);
        }

        if (Schema::hasColumn('products', 'units_per_case')) {
            Schema::table('products', fn (Blueprint $t) => $t->dropColumn('units_per_case'));
        }

        Schema::table('products', function (Blueprint $t) {
            $t->unsignedInteger('units_per_case')->nullable();   // base units in one physical case, for rebate volume
        });

        // An agreement is just data: change a rate, add a tier or a cluster and the next calculation uses it.
        Schema::create('rebate_agreements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->string('name', 150);
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->string('method', 12)->default('all_units');           // all_units | marginal
            $t->string('accounting_treatment', 15)->default('income'); // income | cogs_reduction
            $t->boolean('is_active')->default(true);
            $t->text('note')->nullable();
            $t->timestamps();

            $t->index(['store_id', 'is_active', 'effective_from']);
        });

        // Customer clusters (Standard / Maxi / Mega). A depot belongs to the highest cluster whose
        // "volume above" its previous-quarter volume exceeds. The base cluster has no threshold.
        Schema::create('rebate_clusters', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('rebate_agreement_id')->constrained()->cascadeOnDelete();
            $t->string('name', 60);
            $t->unsignedInteger('volume_above')->nullable();
            $t->unsignedSmallInteger('sort_order')->default(0);
        });

        // Monthly cases from `from_cases` upward earn `rate` percent, until the next tier starts. Below the first tier: 0%.
        Schema::create('rebate_tiers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('rebate_cluster_id')->constrained()->cascadeOnDelete();
            $t->foreignId('rebate_category_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('from_cases');
            $t->decimal('rate', 6, 3);

            $t->unique(['rebate_cluster_id', 'rebate_category_id', 'from_cases'], 'rebate_tiers_cluster_category_from_unique');
        });

        // A calculation snapshots everything it used, so editing the agreement later never changes a past month.
        Schema::create('rebate_calculations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('rebate_agreement_id')->constrained()->restrictOnDelete();
            $t->string('number', 20);
            $t->date('period_start');
            $t->date('period_end');
            $t->string('status', 10)->default('draft');   // draft | approved | settled | void
            // true while the calculation counts; NULL once void. The unique index below means a month can only
            // be claimed once, however many times someone presses Calculate.
            $t->boolean('active')->nullable()->default(true);
            $t->string('method', 12);
            $t->string('accounting_treatment', 15);
            $t->string('cluster_name', 60);
            $t->decimal('cluster_volume', 14, 3);          // cases in the quarter that decided the cluster
            $t->date('cluster_from');
            $t->date('cluster_to');
            $t->boolean('cluster_overridden')->default(false);
            $t->string('override_reason')->nullable();
            $t->bigInteger('total_eligible_value')->default(0);   // kobo
            $t->bigInteger('total_rebate')->default(0);           // kobo
            $t->json('warnings')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->foreignId('accrual_journal_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $t->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('voided_at')->nullable();
            $t->string('void_reason')->nullable();
            $t->string('settlement_method', 12)->nullable();   // bank | credit_note
            $t->date('settled_on')->nullable();
            $t->string('settlement_reference', 80)->nullable();
            $t->foreignId('settled_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('settlement_journal_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $t->timestamps();

            $t->unique(['store_id', 'number']);
            $t->unique(['store_id', 'period_start', 'active']);
        });

        Schema::create('rebate_calculation_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('rebate_calculation_id')->constrained()->cascadeOnDelete();
            $t->foreignId('rebate_category_id')->constrained()->restrictOnDelete();
            $t->string('category_name', 100);
            $t->decimal('cases', 14, 3);
            $t->bigInteger('eligible_value');               // kobo
            $t->unsignedInteger('tier_from')->nullable();   // the tier reached (highest, for the marginal method)
            $t->decimal('tier_rate', 6, 3)->default(0);
            $t->decimal('effective_rate', 7, 4)->default(0);
            $t->bigInteger('rebate');                       // kobo
            $t->boolean('no_tier')->default(false);         // volume earned nothing: below the first tier or no tiers set
        });

        // Which received goods made up each calculation, line by line, for audit and disputes.
        Schema::create('rebate_calculation_sources', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('rebate_calculation_id')->constrained()->cascadeOnDelete();
            $t->foreignId('goods_receipt_line_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->foreignId('rebate_category_id')->constrained()->restrictOnDelete();
            $t->decimal('cases', 14, 3);
            $t->bigInteger('value');                        // kobo
            $t->boolean('cases_assumed')->default(false);   // product had no units_per_case, largest pack used

            $t->index('rebate_calculation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rebate_calculation_sources');
        Schema::dropIfExists('rebate_calculation_lines');
        Schema::dropIfExists('rebate_calculations');
        Schema::dropIfExists('rebate_tiers');
        Schema::dropIfExists('rebate_clusters');
        Schema::dropIfExists('rebate_agreements');
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('units_per_case'));
    }
};
