<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Shared rule for deleting set-up records: if anything real points at it, refuse and say what to do instead. */
class Deletion
{
    /**
     * @param  array<string,string>  $uses  table => column that points at this record
     */
    public static function remove(Model $model, array $uses, string $what): void
    {
        foreach ($uses as $table => $column) {
            abort_if(
                DB::table($table)->where($column, $model->getKey())->exists(),
                422,
                "{$what} has been used (in {$table}) so it cannot be deleted. Mark it inactive instead to stop using it.",
            );
        }

        $model->delete();
    }
}
