<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Standard roles are recognised by a hidden key instead of their name, so every role (Owner and Van Salesman included)
 * can be renamed freely without the system losing track of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('roles', 'system_key')) {
            Schema::table('roles', function ($t) {
                $t->string('system_key', 40)->nullable()->after('guard_name');
            });
        }

        $names = array_merge(array_keys(\App\Support\Permissions::roleTemplates()), ['Field Sales']);

        foreach (DB::table('roles')->whereNull('system_key')->get() as $role) {
            if (in_array($role->name, $names, true)) {
                $key = \App\Support\Permissions::roleKey($role->name);
                DB::table('roles')->where('id', $role->id)->update(['system_key' => $key]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('roles', 'system_key')) {
            Schema::table('roles', fn ($t) => $t->dropColumn('system_key'));
        }
    }
};
