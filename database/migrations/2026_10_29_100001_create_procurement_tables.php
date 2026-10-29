<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->string('name', 150);
            $t->string('code', 20);
            $t->string('contact_name', 100)->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('email', 150)->nullable();
            $t->string('address')->nullable();
            $t->unsignedSmallInteger('payment_terms_days')->default(0);
            $t->boolean('is_manufacturer')->default(false);   // true for Coca-Cola: its receipts feed rebate claims
            $t->boolean('is_active')->default(true);
            $t->timestamps();

            $t->unique(['store_id', 'code']);
        });

        Schema::create('purchase_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $t->string('number', 20);
            $t->string('status', 20)->default('draft');   // draft | ordered | partially_received | received | cancelled
            $t->date('order_date');
            $t->date('expected_date')->nullable();
            $t->text('note')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();

            $t->unique(['store_id', 'number']);
            $t->index(['store_id', 'status']);
        });

        Schema::create('purchase_order_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->foreignId('unit_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('factor');                 // base units per ordered unit, frozen when ordered
            $t->unsignedInteger('quantity');               // in the ordered unit
            $t->decimal('unit_cost', 15, 2);               // per ordered unit
        });

        Schema::create('goods_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $t->foreignId('purchase_order_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('number', 20);
            $t->string('status', 12)->default('draft');   // draft | posted | reversed
            $t->string('supplier_reference', 60)->nullable(); // the supplier's delivery note / invoice number
            $t->date('received_on');
            $t->string('vehicle_no', 30)->nullable();
            $t->text('note')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('posted_at')->nullable();
            $t->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('reversed_at')->nullable();
            $t->string('reversal_reason')->nullable();
            $t->timestamps();

            $t->unique(['store_id', 'number']);
            // The same delivery can never be recorded twice. This is what protects rebate claims from double counting.
            $t->unique(['store_id', 'supplier_id', 'supplier_reference']);
            $t->index(['store_id', 'status', 'received_on']);
        });

        // delivered = accepted + damaged + rejected, all in the entry unit.
        //   accepted -> saleable stock, and the only quantity that can count toward rebates
        //   damaged  -> kept as damaged stock (for a supplier claim)
        //   rejected -> never enters stock
        Schema::create('goods_receipt_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $t->foreignId('purchase_order_line_id')->nullable()->constrained('purchase_order_lines')->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->foreignId('unit_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('factor');
            $t->unsignedInteger('delivered');
            $t->unsignedInteger('accepted');
            $t->unsignedInteger('damaged')->default(0);
            $t->unsignedInteger('rejected')->default(0);
            $t->decimal('unit_cost', 15, 2);               // per entry unit
            $t->string('reason')->nullable();              // why goods were damaged or rejected
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('suppliers');
    }
};
