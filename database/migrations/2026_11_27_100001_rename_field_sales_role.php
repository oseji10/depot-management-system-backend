<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** "Field Sales" is now called "Van Salesman". Existing roles keep their people and permissions; only the name changes. */
return new class extends Migration
{
    public function up(): void
    {
        $roles = DB::table('roles')->where('name', 'Field Sales')->get(['id', 'store_id', 'guard_name']);

        foreach ($roles as $role) {
            $taken = DB::table('roles')->where('name', 'Van Salesman')->where('guard_name', $role->guard_name)->where('store_id', $role->store_id)->exists();

            if (! $taken) {
                DB::table('roles')->where('id', $role->id)->update(['name' => 'Van Salesman']);
            }
        }
    }

    public function down(): void
    {
        DB::table('roles')->where('name', 'Van Salesman')->update(['name' => 'Field Sales']);
    }
};
