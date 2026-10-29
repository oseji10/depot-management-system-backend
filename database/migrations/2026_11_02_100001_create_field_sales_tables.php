<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['trip_load_lines', 'trip_loads', 'trip_lines', 'trips'] as $table) {
            Schema::dropIfExists($table);   // safe to re-run after a partial failure
        }

        // A salesperson's van is a warehouse of its own. Stock issued to them sits there until it is sold or returned.
        if (! Schema::hasColumn('warehouses', 'type')) {
            Schema::table('warehouses', function (Blueprint $t) {
                $t->string('type', 10)->default('standard')->after('code');   // standard | van
                $t->unsignedBigInteger('assigned_user_id')->nullable()->after('type');
                $t->index('assigned_user_id', 'warehouses_assigned_user_idx');
            });
        }

        Schema::create('trips', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->string('number', 20);
            $t->foreignId('rep_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('van_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $t->string('status', 10)->default('open');             // open | closed
            $t->timestamp('opened_at');
            $t->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('closed_at')->nullable();
            $t->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->unsignedBigInteger('cash_expected')->nullable();    // kobo of cash the rep should hand in, fixed at check-in
            $t->unsignedBigInteger('cash_handed_in')->nullable();
            $t->bigInteger('cash_variance')->nullable();            // handed in minus expected; negative = short
            $t->string('note', 500)->nullable();
            $t->string('close_note', 500)->nullable();
            $t->timestamps();

            $t->unique(['store_id', 'number']);
            $t->index(['store_id', 'status']);
            $t->index(['rep_id', 'status']);
        });

        Schema::create('trip_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('issued')->default(0);      // base units loaded onto the van
            $t->unsignedInteger('returned')->default(0);    // came back saleable
            $t->unsignedInteger('damaged')->default(0);     // came back broken or expired, written off
            $t->unsignedInteger('shortage')->default(0);    // never came back and was not sold, written off at check-in
            $t->timestamps();

            $t->unique(['trip_id', 'product_id'], 'trip_lines_trip_product_unique');
        });

        // One row per time stock was put on, or taken off, the van. Stock movements point back at these.
        Schema::create('trip_loads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 8);                                  // issue | return
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();   // the warehouse on the other side
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('note', 500)->nullable();
            $t->timestamps();
        });

        Schema::create('trip_load_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('trip_load_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->string('status', 10)->default('saleable');          // saleable | damaged | shortage
            $t->unsignedInteger('quantity');                        // base units
            $t->timestamps();
        });

        if (! Schema::hasColumn('sales', 'trip_id')) {
            Schema::table('sales', function (Blueprint $t) {
                $t->unsignedBigInteger('trip_id')->nullable()->after('warehouse_id');
                $t->index('trip_id', 'sales_trip_idx');
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty.
    }
};
