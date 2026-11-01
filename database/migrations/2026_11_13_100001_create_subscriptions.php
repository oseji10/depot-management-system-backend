<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What the platform sells.
        Schema::create('plans', function (Blueprint $t) {
            $t->id();
            $t->string('name', 80);
            $t->string('description', 255)->nullable();
            $t->unsignedBigInteger('price');                // kobo, for the whole period
            $t->unsignedSmallInteger('months')->default(1); // how long one payment buys
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
        });

        Schema::create('platform_settings', function (Blueprint $t) {
            $t->string('key', 60)->primary();
            $t->string('value', 255)->nullable();
        });

        // One row per business: where it stands, and any manual override by the platform admin.
        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->unique()->constrained()->cascadeOnDelete();
            $t->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $t->string('kind', 8)->default('trial');        // trial | paid
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();           // null = never ends
            $t->unsignedSmallInteger('readonly_days')->nullable();   // this business only; null = the platform setting
            $t->string('override_mode', 10)->nullable();    // full | readonly | blocked; null = follow the dates
            $t->timestamp('override_until')->nullable();    // null = until changed
            $t->string('override_note', 255)->nullable();
            $t->foreignId('override_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('subscription_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $t->unsignedBigInteger('amount');               // kobo
            $t->unsignedSmallInteger('months')->default(1);
            $t->string('method', 10);                       // manual | paystack
            $t->string('status', 10)->default('success');   // pending | success | failed
            $t->string('reference', 80)->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamp('period_from')->nullable();
            $t->timestamp('period_to')->nullable();
            $t->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('note', 255)->nullable();
            $t->timestamps();
            $t->unique('reference');
            $t->index(['store_id', 'status']);
        });

        DB::table('platform_settings')->insert([['key' => 'trial_days', 'value' => '14'], ['key' => 'readonly_days', 'value' => '14']]);

        // Businesses that already exist keep full access until the platform admin decides otherwise.
        foreach (DB::table('stores')->pluck('id') as $storeId) {
            DB::table('subscriptions')->insert([
                'store_id' => $storeId, 'kind' => 'paid', 'starts_at' => now(), 'override_mode' => 'full', 'override_note' => 'Existing customer', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('plans');
    }
};
