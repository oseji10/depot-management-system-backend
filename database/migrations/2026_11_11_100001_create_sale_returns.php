<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // A credit note: goods a customer sends back after buying. Never edited; a mistake is a new, opposite entry.
        Schema::create('sale_returns', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();   // where the goods went back
            $t->foreignId('sale_id')->constrained()->restrictOnDelete();
            $t->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('number', 20);
            $t->timestamp('returned_at');
            $t->string('reason', 255);
            $t->string('restock_status', 10)->default('saleable');              // saleable | damaged
            $t->unsignedBigInteger('total');                                    // value of the goods, at the price they paid
            $t->unsignedBigInteger('cogs_back')->default(0);                    // cost of the goods, back into inventory
            $t->unsignedBigInteger('applied_to_balance')->default(0);           // taken off what the customer still owed
            $t->unsignedBigInteger('refunded')->default(0);                     // money handed back
            $t->string('refund_method', 10)->nullable();                        // cash | transfer
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();

            $t->unique(['store_id', 'number']);
            $t->index(['sale_id']);
            $t->index(['store_id', 'returned_at']);
        });

        Schema::create('sale_return_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('sale_return_id')->constrained()->cascadeOnDelete();
            $t->foreignId('sale_line_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $t->unsignedInteger('factor');
            $t->unsignedInteger('quantity');
            $t->unsignedBigInteger('unit_price');
            $t->unsignedBigInteger('line_total');
            $t->unsignedBigInteger('cost_total')->default(0);
            $t->timestamps();

            $t->index('sale_line_id');
        });

        Schema::table('sales', function (Blueprint $t) {
            $t->unsignedBigInteger('returned_total')->default(0)->after('balance_due');
        });
    }

    public function down(): void
    {
        Schema::table('sales', fn (Blueprint $t) => $t->dropColumn('returned_total'));
        Schema::dropIfExists('sale_return_lines');
        Schema::dropIfExists('sale_returns');
    }
};
