<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Extra hours worked, approved, then paid as an earning on the next payroll run. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('employees', 'overtime_rate')) {
            Schema::table('employees', function (Blueprint $t) {
                $t->unsignedBigInteger('overtime_rate')->nullable()->after('basic_salary'); // kobo per hour
            });
        }

        if (! Schema::hasTable('overtime_entries')) {
            Schema::create('overtime_entries', function (Blueprint $t) {
                $t->id();
                $t->foreignId('store_id')->constrained()->cascadeOnDelete();
                $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
                $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
                $t->date('worked_on');
                $t->decimal('hours', 6, 2);
                $t->unsignedBigInteger('rate');   // kobo per hour
                $t->unsignedBigInteger('amount'); // kobo
                $t->string('note', 255)->nullable();
                $t->string('status', 12)->default('pending'); // pending | approved | rejected | paid
                $t->string('decision_note', 255)->nullable();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('decided_at')->nullable();
                $t->foreignId('payroll_run_id')->nullable()->constrained('payroll_runs')->nullOnDelete();
                $t->timestamps();
                $t->index(['store_id', 'status', 'worked_on']);
                $t->index(['employee_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_entries');

        if (Schema::hasColumn('employees', 'overtime_rate')) {
            Schema::table('employees', fn (Blueprint $t) => $t->dropColumn('overtime_rate'));
        }
    }
};
