<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A login can work in several branches. users.branch_id stays as their main branch (where they start). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_branches')) {
            Schema::create('user_branches', function (Blueprint $t) {
                $t->id();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();
                $t->foreignId('branch_id')->constrained()->cascadeOnDelete();
                $t->timestamps();
                $t->unique(['user_id', 'branch_id']);
            });
        }

        DB::table('users')->whereNotNull('branch_id')->orderBy('id')->select(['id', 'branch_id'])->chunk(500, function ($rows) {
            DB::table('user_branches')->insertOrIgnore($rows->map(fn ($u) => ['user_id' => $u->id, 'branch_id' => $u->branch_id, 'created_at' => now(), 'updated_at' => now()])->all());
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_branches');
    }
};
