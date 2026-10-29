<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->boolean('tracks_expiry')->default(false)->after('is_returnable');
            $t->unsignedSmallInteger('expiry_alert_days')->nullable()->after('tracks_expiry'); // null = store default (30)
        });

        // One row per warehouse, product, batch number and expiry date: how many of that batch are still saleable.
        Schema::create('stock_batches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->string('batch_no', 40)->default('');
            $t->date('expiry_date');
            $t->integer('quantity')->default(0);
            $t->timestamps();

            $t->unique(['warehouse_id', 'product_id', 'batch_no', 'expiry_date'], 'stock_batches_unique');
            $t->index(['store_id', 'expiry_date']);
        });

        // Which batches each stock movement took from (negative) or put into (positive).
        Schema::create('stock_movement_batches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('stock_movement_id')->constrained()->cascadeOnDelete();
            $t->foreignId('stock_batch_id')->constrained()->restrictOnDelete();
            $t->integer('quantity');
            $t->index('stock_movement_id');
        });

        Schema::table('goods_receipt_lines', function (Blueprint $t) {
            $t->string('batch_no', 40)->nullable();
            $t->date('expiry_date')->nullable();
        });

        Schema::table('stock_adjustment_lines', function (Blueprint $t) {
            $t->string('batch_no', 40)->nullable();
            $t->date('expiry_date')->nullable();
            $t->foreignId('stock_batch_id')->nullable()->constrained('stock_batches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_adjustment_lines', function (Blueprint $t) {
            $t->dropConstrainedForeignId('stock_batch_id');
            $t->dropColumn(['batch_no', 'expiry_date']);
        });
        Schema::table('goods_receipt_lines', fn (Blueprint $t) => $t->dropColumn(['batch_no', 'expiry_date']));
        Schema::dropIfExists('stock_movement_batches');
        Schema::dropIfExists('stock_batches');
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['tracks_expiry', 'expiry_alert_days']));
    }
};
