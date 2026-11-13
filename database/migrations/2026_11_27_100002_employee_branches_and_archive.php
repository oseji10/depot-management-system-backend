<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** An employee can work across several branches (the first is their main one, used for payroll), and can be archived or deleted. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_branches')) {
            Schema::create('employee_branches', function (Blueprint $t) {
                $t->id();
                $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
                $t->foreignId('branch_id')->constrained()->cascadeOnDelete();
                $t->unique(['employee_id', 'branch_id']);
            });

            foreach (DB::table('employees')->get(['id', 'branch_id']) as $e) {
                DB::table('employee_branches')->insertOrIgnore(['employee_id' => $e->id, 'branch_id' => $e->branch_id]);
            }
        }

        if (! Schema::hasColumn('employees', 'deleted_at')) {
            Schema::table('employees', fn (Blueprint $t) => $t->softDeletes());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_branches');

        if (Schema::hasColumn('employees', 'deleted_at')) {
            Schema::table('employees', fn (Blueprint $t) => $t->dropSoftDeletes());
        }
    }
};
