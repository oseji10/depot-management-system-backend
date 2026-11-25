<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A van is linked to the person who drives and sells from it. Anyone linked to a van is treated as a van salesman
 * (short "My van" menu, sees only their own sales). Some office staff (an Accountant, say) were first added as van
 * salesmen and later given another role, so they kept the van link and the van menu. This unlinks vans from people
 * whose role is now a built-in office role. The vans and their stock are untouched. People on custom roles are left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('roles', 'system_key')) {
            return;
        }

        $vans = DB::table('warehouses')->where('type', 'van')->whereNotNull('assigned_user_id')->get(['id', 'assigned_user_id']);

        foreach ($vans as $van) {
            $keys = DB::table('model_has_roles as m')->join('roles as r', 'r.id', '=', 'm.role_id')
                ->where('m.model_id', $van->assigned_user_id)->where('m.model_type', 'like', '%User')
                ->pluck('r.system_key');

            $office = $keys->isNotEmpty() && $keys->every(fn ($k) => $k !== null && $k !== 'van_salesman');
            if ($office) {
                DB::table('warehouses')->where('id', $van->id)->update(['assigned_user_id' => null]);
            }
        }
    }

    public function down(): void
    {
        // Links removed here are not restored.
    }
};
