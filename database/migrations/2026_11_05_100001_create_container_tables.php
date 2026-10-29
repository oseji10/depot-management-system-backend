<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('container_types', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->string('kind', 10);                       // bottle | crate
            $t->unsignedBigInteger('deposit')->default(0); // kobo, charged per container to deposit-mode customers
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['store_id', 'name']);
        });

        // One row per event. Balances are always sums of these rows, so history can never disagree with a balance.
        Schema::create('container_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('container_type_id')->constrained();
            $t->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $t->string('party_type', 10)->nullable();      // customer | supplier
            $t->unsignedBigInteger('party_id')->nullable(); // null customer = walk-in
            $t->string('kind', 20);
            $t->integer('qty_party')->default(0);          // + = party holds more of our containers (supplier: depot owes more)
            $t->integer('qty_depot')->default(0);          // + = more empties on the depot's shelf
            $t->bigInteger('deposit')->default(0);         // kobo; + = more deposit held for customers
            $t->nullableMorphs('source');
            $t->string('note', 255)->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['store_id', 'container_type_id']);
            $t->index(['store_id', 'party_type', 'party_id']);
        });

        Schema::table('products', function (Blueprint $t) {
            $t->foreignId('bottle_container_id')->nullable()->constrained('container_types')->nullOnDelete();
            $t->foreignId('crate_container_id')->nullable()->constrained('container_types')->nullOnDelete();
        });

        Schema::table('customers', function (Blueprint $t) {
            $t->string('empties_mode', 10)->default('deposit');   // deposit | balance | none
        });
        DB::table('customers')->where('type', 'wholesale')->update(['empties_mode' => 'balance']);

        Schema::table('sales', function (Blueprint $t) {
            $t->unsignedBigInteger('deposit_total')->default(0);   // container deposits charged on top of total
        });
    }

    public function down(): void
    {
        Schema::table('sales', fn (Blueprint $t) => $t->dropColumn('deposit_total'));
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('empties_mode'));
        Schema::table('products', function (Blueprint $t) {
            $t->dropConstrainedForeignId('bottle_container_id');
            $t->dropConstrainedForeignId('crate_container_id');
        });
        Schema::dropIfExists('container_movements');
        Schema::dropIfExists('container_types');
    }
};
