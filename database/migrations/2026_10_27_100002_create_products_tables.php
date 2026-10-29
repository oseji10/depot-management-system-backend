<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->string('sku', 40);
            $t->string('name', 150);
            $t->string('brand', 60)->nullable();
            $t->string('size_label', 30)->nullable();            // e.g. 50cl, 1ltr
            $t->string('packaging', 10)->default('other');       // rgb | pet | can | other
            $t->boolean('is_returnable')->default(false);        // bottle/crate tracking arrives in the containers phase
            $t->foreignId('category_id')->constrained('product_categories')->restrictOnDelete();
            $t->foreignId('rebate_category_id')->nullable()->constrained('rebate_categories')->restrictOnDelete();
            $t->foreignId('base_unit_id')->constrained('units')->restrictOnDelete();
            $t->boolean('is_active')->default(true);
            $t->timestamps();

            $t->unique(['store_id', 'sku']);
        });

        // One row per way a product can be bought or sold. The base unit always has factor 1;
        // a crate of 24 bottles is factor 24. Stock is always held in base units.
        Schema::create('product_units', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->foreignId('unit_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('factor')->default(1);
            $t->string('barcode', 64)->nullable();
            $t->boolean('is_base')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();

            $t->unique(['product_id', 'unit_id']);
            $t->unique(['store_id', 'barcode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_units');
        Schema::dropIfExists('products');
    }
};
