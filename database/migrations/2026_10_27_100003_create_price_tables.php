<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('price_lists', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->string('name', 100);
            $t->string('type', 10)->default('standard');   // standard | promo
            $t->date('starts_on')->nullable();             // promos only
            $t->date('ends_on')->nullable();
            $t->unsignedSmallInteger('priority')->default(0); // higher wins when promos overlap
            $t->boolean('is_default')->default(false);     // the one standard list prices fall back to
            $t->boolean('is_active')->default(true);
            $t->timestamps();

            $t->unique(['store_id', 'name']);
        });

        Schema::create('product_prices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('price_list_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_unit_id')->constrained()->restrictOnDelete();
            $t->decimal('price', 15, 2);
            $t->timestamps();

            $t->unique(['price_list_id', 'product_unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_prices');
        Schema::dropIfExists('price_lists');
    }
};
