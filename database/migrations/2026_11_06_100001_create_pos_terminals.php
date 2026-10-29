<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pos_terminals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();           // null = any branch
            $t->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete(); // a rep who carries it
            $t->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();  // where its money lands in the books; null = the main Bank account
            $t->string('name', 80);
            $t->string('provider', 80)->nullable();        // Moniepoint, Opay, a bank...
            $t->string('terminal_id', 40)->nullable();     // the TID printed on the device / provider dashboard
            $t->string('merchant_id', 40)->nullable();
            $t->string('serial_number', 60)->nullable();
            $t->text('notes')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['store_id', 'name']);
        });

        Schema::table('sale_payments', function (Blueprint $t) {
            $t->foreignId('pos_terminal_id')->nullable()->after('method')->constrained('pos_terminals')->nullOnDelete();
            $t->index(['pos_terminal_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::table('sale_payments', fn (Blueprint $t) => $t->dropConstrainedForeignId('pos_terminal_id'));
        Schema::dropIfExists('pos_terminals');
    }
};
