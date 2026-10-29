<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->foreignId('account_id')->constrained('accounts');   // the expense account it posts to
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['store_id', 'name'], 'expense_categories_name_unique');
        });

        Schema::create('expenses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->foreignId('branch_id')->constrained();
            $t->string('number', 24);
            $t->foreignId('category_id')->constrained('expense_categories');
            $t->date('expense_date');
            $t->unsignedBigInteger('amount');                         // kobo
            $t->string('method', 12);                                 // cash | bank
            $t->string('paid_to', 120)->nullable();
            $t->string('description', 255)->nullable();
            $t->foreignId('trip_id')->nullable()->constrained('trips')->nullOnDelete();
            $t->string('client_ref', 64)->nullable();
            $t->string('status', 12)->default('posted');              // posted | void
            $t->boolean('offline')->default(false);
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('voided_by')->nullable()->constrained('users');
            $t->timestamp('voided_at')->nullable();
            $t->string('void_reason', 255)->nullable();
            $t->timestamps();
            $t->unique(['store_id', 'number'], 'expenses_number_unique');
            $t->unique(['store_id', 'client_ref'], 'expenses_client_ref_unique');
            $t->index(['store_id', 'branch_id', 'expense_date'], 'expenses_lookup_idx');
            $t->index('trip_id', 'expenses_trip_idx');
        });

        // Things a phone sent that the server could not accept, waiting for a manager to retry or dismiss.
        Schema::create('sync_issues', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->foreignId('branch_id')->nullable()->constrained();
            $t->foreignId('user_id')->constrained('users');
            $t->foreignId('trip_id')->nullable()->constrained('trips')->nullOnDelete();
            $t->string('kind', 12);                                   // sale | customer | expense
            $t->string('client_ref', 64);
            $t->json('payload');
            $t->string('error', 500);
            $t->unsignedSmallInteger('attempts')->default(1);
            $t->string('status', 12)->default('open');                // open | resolved | dismissed
            $t->foreignId('resolved_by')->nullable()->constrained('users');
            $t->timestamp('resolved_at')->nullable();
            $t->string('resolution', 255)->nullable();
            $t->timestamps();
            $t->unique(['store_id', 'kind', 'client_ref'], 'sync_issues_ref_unique');
            $t->index(['store_id', 'status'], 'sync_issues_status_idx');
        });

        Schema::table('customers', function (Blueprint $t) {
            $t->string('client_ref', 64)->nullable()->after('store_id');
            $t->unique(['store_id', 'client_ref'], 'customers_client_ref_unique');
        });

        Schema::table('sales', function (Blueprint $t) {
            $t->boolean('offline')->default(false)->after('client_ref');
            $t->timestamp('synced_at')->nullable()->after('offline');
        });

        Schema::table('trips', function (Blueprint $t) {
            $t->unsignedSmallInteger('device_pending')->default(0);   // items the phone says it has not sent yet
            $t->timestamp('device_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('trips', fn (Blueprint $t) => $t->dropColumn(['device_pending', 'device_seen_at']));
        Schema::table('sales', fn (Blueprint $t) => $t->dropColumn(['offline', 'synced_at']));
        Schema::table('customers', function (Blueprint $t) {
            $t->dropUnique('customers_client_ref_unique');
            $t->dropColumn('client_ref');
        });
        Schema::dropIfExists('sync_issues');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
    }
};
