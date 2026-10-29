<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->string('code', 10);
            $t->string('name', 120);
            // asset | liability | equity | revenue | other_income | cogs | expense
            $t->string('type', 15);
            // System roles let the code find "the inventory account" however the store renamed or renumbered it.
            $t->string('role', 30)->nullable();
            $t->boolean('is_system')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();

            $t->unique(['store_id', 'code']);
            $t->unique(['store_id', 'role']);
        });

        Schema::create('fiscal_periods', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('status', 10)->default('open');   // open | closed
            $t->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();

            $t->unique(['store_id', 'starts_on']);
        });

        Schema::create('journal_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('number', 20);
            $t->string('status', 10)->default('draft');   // draft | posted
            $t->date('entry_date');
            $t->string('memo', 255);
            $t->unsignedBigInteger('total')->default(0);  // kobo, sum of debits
            $t->nullableMorphs('source');
            $t->string('posting_key', 80)->nullable();    // makes automatic postings idempotent
            $t->foreignId('reversal_of_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $t->timestamp('reversed_at')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('posted_at')->nullable();
            $t->timestamps();

            $t->unique(['store_id', 'number']);
            $t->unique(['store_id', 'posting_key']);
            $t->index(['store_id', 'status', 'entry_date']);
        });

        Schema::create('journal_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $t->foreignId('account_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('debit')->default(0);   // kobo
            $t->unsignedBigInteger('credit')->default(0);  // kobo
            $t->string('memo', 255)->nullable();

            $t->index(['store_id', 'account_id']);
        });

        // Weighted-average cost of saleable stock, store-wide. quantity mirrors saleable stock in base units.
        Schema::create('product_costs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->bigInteger('quantity')->default(0);
            $t->bigInteger('value')->default(0);           // kobo
            $t->timestamps();

            $t->unique(['store_id', 'product_id']);
        });

        Schema::table('stock_adjustment_lines', function (Blueprint $t) {
            $t->decimal('unit_cost', 15, 4)->nullable();   // naira per base unit, for stock added without a known cost
        });
    }

    public function down(): void
    {
        Schema::table('stock_adjustment_lines', fn (Blueprint $t) => $t->dropColumn('unit_cost'));
        Schema::dropIfExists('product_costs');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('fiscal_periods');
        Schema::dropIfExists('accounts');
    }
};
