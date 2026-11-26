<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** PDFs (payslips, receipts) sent to people on WhatsApp as a link: the chat opens on their number with a link to the file. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->string('token', 64)->unique();
            $t->string('kind', 20);
            $t->unsignedBigInteger('ref_id')->nullable();
            $t->string('filename', 150);
            $t->string('path');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->unsignedInteger('views')->default(0);
            $t->timestamp('expires_at');
            $t->timestamps();
            $t->index(['store_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_documents');
    }
};
