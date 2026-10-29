<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // A way to split one crate into rows, each row a different product (e.g. 4 rows of 6 = 24).
        Schema::create('mix_layouts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->string('name', 60);
            $t->unsignedSmallInteger('row_count');
            $t->unsignedSmallInteger('row_size');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['store_id', 'row_count', 'row_size']);
        });

        Schema::table('sale_lines', function (Blueprint $t) {
            $t->unsignedBigInteger('mix_layout_id')->nullable();
            $t->string('mix_group', 20)->nullable();
            $t->string('mix_label', 60)->nullable();   // the layout's name when sold, kept even if it is renamed later
        });
    }

    public function down(): void
    {
        Schema::table('sale_lines', fn (Blueprint $t) => $t->dropColumn(['mix_layout_id', 'mix_group', 'mix_label']));
        Schema::dropIfExists('mix_layouts');
    }
};
