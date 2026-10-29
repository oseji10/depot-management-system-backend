<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('app_notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 30);                         // milestone | stock | expiry | fleet | sync | approval | payroll
            $t->string('severity', 10)->default('info');    // success | info | warning | alert
            $t->string('title');
            $t->string('body', 500)->nullable();
            $t->string('url')->nullable();                  // page the "Open page" button goes to
            $t->string('permission', 60)->nullable();       // who may see it; null = every head-office user
            $t->string('dedupe_key', 120);                  // the same event is only announced once
            $t->timestamps();
            $t->unique(['store_id', 'dedupe_key']);
            $t->index(['store_id', 'id']);
        });

        Schema::create('notification_reads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('notification_id')->constrained('app_notifications')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestamp('read_at')->useCurrent();
            $t->unique(['notification_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_reads');
        Schema::dropIfExists('app_notifications');
    }
};
