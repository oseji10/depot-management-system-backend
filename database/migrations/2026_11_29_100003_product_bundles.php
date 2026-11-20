<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A bundle is a product made of other products. It has no stock of its own: selling one takes each item out of stock,
 * and a void or return puts them back.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'is_bundle')) {
            Schema::table('products', function (Blueprint $t) {
                $t->boolean('is_bundle')->default(false)->after('packaging');
            });
        }

        if (! Schema::hasTable('product_bundle_items')) {
            Schema::create('product_bundle_items', function (Blueprint $t) {
                $t->id();
                $t->foreignId('bundle_id')->constrained('products')->cascadeOnDelete();
                $t->foreignId('product_id')->constrained('products');
                $t->foreignId('unit_id')->constrained('units');
                $t->unsignedInteger('quantity');      // in that variation, e.g. 2 crates
                $t->unsignedInteger('base_quantity'); // the same in the smallest unit, e.g. 48 bottles
                $t->timestamps();
                $t->unique(['bundle_id', 'product_id', 'unit_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_bundle_items');

        if (Schema::hasColumn('products', 'is_bundle')) {
            Schema::table('products', fn (Blueprint $t) => $t->dropColumn('is_bundle'));
        }
    }
};
