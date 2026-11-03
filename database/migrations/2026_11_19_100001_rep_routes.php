<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // The routes (customer groups of kind "route") a salesperson covers. A salesperson with none sees every customer.
        Schema::create('rep_routes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_group_id')->constrained()->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['user_id', 'customer_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rep_routes');
    }
};
