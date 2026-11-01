<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which businesses a person belongs to. users.store_id stays the one they are working in right now.
        Schema::create('user_stores', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $t->boolean('is_owner')->default(false);
            $t->timestamps();
            $t->unique(['user_id', 'store_id']);
        });

        Schema::table('plans', fn (Blueprint $t) => $t->unsignedSmallInteger('max_businesses')->default(1));
        DB::table('platform_settings')->insert(['key' => 'default_max_businesses', 'value' => '1']);

        $owners = DB::table('model_has_roles as m')->join('roles as r', 'r.id', '=', 'm.role_id')
            ->where('r.name', 'Owner')->where('m.model_type', 'like', '%User')->pluck('m.model_id')->all();

        foreach (DB::table('users')->whereNotNull('store_id')->get(['id', 'store_id', 'branch_id']) as $u) {
            DB::table('user_stores')->insert(['user_id' => $u->id, 'store_id' => $u->store_id, 'branch_id' => $u->branch_id, 'is_owner' => in_array($u->id, $owners), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('plans', fn (Blueprint $t) => $t->dropColumn('max_businesses'));
        DB::table('platform_settings')->where('key', 'default_max_businesses')->delete();
        Schema::dropIfExists('user_stores');
    }
};
