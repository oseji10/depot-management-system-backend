<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // THE source of truth. Rows are only ever inserted, never changed or removed.
        // quantity is signed and always in the product's base unit.
        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->string('status', 12);                       // saleable | damaged | expired
            $t->integer('quantity');                        // +in / -out
            $t->string('movement_type', 24);                // opening, adjustment, reclassify, transfer_out, transfer_in, count_variance, ...
            $t->string('source_type', 100)->nullable();     // the document that caused it
            $t->unsignedBigInteger('source_id')->nullable();
            $t->string('reference', 60)->nullable();        // human readable document number
            $t->string('note')->nullable();
            $t->decimal('unit_cost', 15, 4)->nullable();    // per base unit; filled from goods receipts later
            $t->string('idempotency_key', 80)->nullable();  // makes retries and offline sync safe
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('occurred_at');
            $t->timestamp('created_at')->useCurrent();

            $t->index(['warehouse_id', 'product_id', 'status']);
            $t->index(['store_id', 'occurred_at']);
            $t->index(['source_type', 'source_id']);
            $t->unique(['store_id', 'idempotency_key']);
        });

        // A fast, rebuildable copy of SUM(stock_movements). `php artisan stock:reconcile` proves they agree.
        Schema::create('stock_balances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->string('status', 12);
            $t->integer('quantity')->default(0);
            $t->timestamps();

            $t->unique(['warehouse_id', 'product_id', 'status']);
        });

        Schema::create('reorder_levels', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('min_quantity');
            $t->timestamps();

            $t->unique(['warehouse_id', 'product_id']);
        });

        Schema::create('document_sequences', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->string('prefix', 8);
            $t->unsignedInteger('last_number')->default(0);
            $t->timestamps();

            $t->unique(['store_id', 'prefix']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
        Schema::dropIfExists('reorder_levels');
        Schema::dropIfExists('stock_balances');
        Schema::dropIfExists('stock_movements');
    }
};
