<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Sales categories: how the depot browses and prices products (RGB crates, PET, cans...).
        Schema::create('product_categories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('parent_id')->nullable()->constrained('product_categories')->restrictOnDelete();
            $t->string('name', 100);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();

            $t->unique(['store_id', 'name']);
        });

        // Rebate categories: how Coca-Cola groups volume for rebate tiers (Sparkling, Water, Juice...).
        // Tiers and rates arrive in the rebate phase; here we only need the grouping.
        Schema::create('rebate_categories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->string('code', 40);
            $t->string('name', 100);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();

            $t->unique(['store_id', 'code']);
        });

        Schema::create('units', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->string('name', 50);
            $t->string('abbreviation', 10);
            $t->timestamps();

            $t->unique(['store_id', 'abbreviation']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
        Schema::dropIfExists('rebate_categories');
        Schema::dropIfExists('product_categories');
    }
};
