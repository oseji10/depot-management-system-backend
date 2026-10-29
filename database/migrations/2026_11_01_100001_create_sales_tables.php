<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['sale_payments', 'sale_lines', 'sales', 'customers'] as $table) {
            Schema::dropIfExists($table);   // a failed earlier run leaves partial tables (MySQL DDL is not transactional)
        }

        // Customers belong to the whole depot, so any branch can sell to them. Money is integer kobo throughout.
        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->string('name', 150);
            $t->string('type', 12)->default('retail');          // retail | wholesale
            $t->string('phone', 30)->nullable();
            $t->string('email', 150)->nullable();
            $t->string('address', 255)->nullable();
            $t->unsignedBigInteger('credit_limit')->default(0); // 0 = cash only. The most this customer may owe at once.
            $t->string('notes', 500)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();

            $t->index(['store_id', 'name']);
            $t->unique(['store_id', 'phone']);
        });

        Schema::create('sales', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete(); // null = walk-in
            $t->string('number', 20);
            $t->string('client_ref', 64)->nullable();            // set by the till so a retry or offline re-sync never sells twice
            $t->string('channel', 12)->default('counter');       // counter | phone | delivery
            $t->string('status', 10)->default('completed');      // completed | void
            $t->string('payment_status', 10)->default('paid');   // paid | partial | unpaid
            $t->timestamp('sold_at');
            $t->unsignedBigInteger('subtotal');                  // at list price
            $t->unsignedBigInteger('discount_total')->default(0);
            $t->unsignedBigInteger('total');
            $t->unsignedBigInteger('amount_paid')->default(0);
            $t->unsignedBigInteger('balance_due')->default(0);
            $t->unsignedBigInteger('cogs_total')->default(0);    // cost of what was sold, at the average cost of the day
            $t->string('note', 500)->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('voided_at')->nullable();
            $t->string('void_reason', 255)->nullable();
            $t->timestamps();

            $t->unique(['store_id', 'number']);
            $t->unique(['store_id', 'client_ref']);
            $t->index(['store_id', 'sold_at']);
            $t->index(['customer_id', 'status']);
            $t->index(['branch_id', 'sold_at']);
        });

        Schema::create('sale_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $t->unsignedInteger('factor');                       // base units per unit sold, frozen at the time of sale
            $t->unsignedInteger('quantity');                     // in the unit sold
            $t->unsignedBigInteger('list_price');                // per unit sold
            $t->unsignedBigInteger('unit_price');
            $t->unsignedBigInteger('line_total');
            $t->unsignedBigInteger('cost_total')->default(0);
            $t->timestamps();

            $t->index('sale_id');
        });

        Schema::create('sale_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $t->string('method', 12);                            // cash | transfer | pos
            $t->unsignedBigInteger('amount');
            $t->string('reference', 80)->nullable();
            $t->timestamp('paid_at');
            $t->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('voided_at')->nullable();
            $t->timestamps();

            $t->index(['sale_id']);
            $t->index(['store_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_payments');
        Schema::dropIfExists('sale_lines');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('customers');
    }
};
