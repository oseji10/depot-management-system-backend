<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('goods_receipt_files')) {
            return;
        }

        // The manufacturer's invoice, delivery note or receipt, photographed or scanned, kept with the goods receipt.
        Schema::create('goods_receipt_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $t->string('path');
            $t->string('original_name', 150);
            $t->string('mime', 60);
            $t->unsignedInteger('size');
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index('goods_receipt_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_files');
    }
};
