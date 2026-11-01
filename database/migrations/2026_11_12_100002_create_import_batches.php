<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('uuid', 40);                       // made by the browser, so every chunk of one file lands in one batch
            $t->string('kind', 12);                       // customers | products | orders
            $t->string('file_name', 200)->nullable();
            $t->unsignedInteger('rows_total')->default(0);
            $t->unsignedInteger('rows_sent')->default(0);
            $t->json('counts')->nullable();
            $t->unsignedInteger('problems')->default(0);
            $t->string('status', 10)->default('active');  // active | undone
            $t->foreignId('undone_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('undone_at')->nullable();
            $t->string('undo_note', 500)->nullable();
            $t->timestamps();
            $t->unique(['store_id', 'uuid']);
        });

        foreach (['customers', 'products', 'sales'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('import_batch_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        foreach (['customers', 'products', 'sales'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('import_batch_id'));
        }
        Schema::dropIfExists('import_batches');
    }
};
