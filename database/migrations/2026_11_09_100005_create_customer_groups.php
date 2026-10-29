<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Groups the business makes up itself: a route, an area, "bar owners", "wedding clients"...
        Schema::create('customer_groups', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->string('name', 100);
            $t->string('kind', 12)->default('other');     // route | location | segment | other
            $t->string('notes')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['store_id', 'name']);
        });

        Schema::create('customer_group_members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_group_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['customer_group_id', 'customer_id']);
            $t->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_group_members');
        Schema::dropIfExists('customer_groups');
    }
};
