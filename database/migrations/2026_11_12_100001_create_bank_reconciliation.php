<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One imported bank (or POS provider) statement for one ledger account.
        Schema::create('bank_statements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $t->string('name', 120);
            $t->date('period_from');
            $t->date('period_to');
            $t->bigInteger('opening_balance')->nullable();   // kobo, as printed on the statement
            $t->bigInteger('closing_balance')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['store_id', 'account_id']);
        });

        Schema::create('bank_statement_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->foreignId('bank_statement_id')->constrained()->cascadeOnDelete();
            $t->date('txn_date');
            $t->string('description', 255)->nullable();
            $t->string('reference', 120)->nullable();
            $t->bigInteger('amount');                         // kobo; money in is positive, money out is negative
            $t->string('status', 10)->default('unmatched');   // unmatched | matched | ignored
            $t->string('note', 255)->nullable();
            $t->timestamps();
            $t->index(['bank_statement_id', 'status']);
        });

        // A statement line can be explained by one or several ledger lines (a POS settlement covers many card payments).
        Schema::create('bank_statement_matches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->foreignId('bank_statement_line_id')->constrained()->cascadeOnDelete();
            $t->foreignId('journal_line_id')->constrained()->cascadeOnDelete();
            $t->unique('journal_line_id');                    // a ledger line is matched at most once
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_matches');
        Schema::dropIfExists('bank_statement_lines');
        Schema::dropIfExists('bank_statements');
    }
};
