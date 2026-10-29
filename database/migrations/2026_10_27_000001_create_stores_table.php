<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A "store" is the tenant: one depot business that buys the software.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->string('legal_name')->nullable();
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->text('address')->nullable();
            $t->string('state')->nullable();
            $t->string('country', 2)->default('NG');
            $t->string('currency', 3)->default('NGN');
            $t->string('timezone')->default('Africa/Lagos');
            $t->boolean('is_active')->default(true);
            $t->json('settings')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};
