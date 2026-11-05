<?php

namespace App\Support\Concerns;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Soft delete for records with a unique name or code. A deleted record keeps its row (so nothing is lost), but its
 * unique value is tagged ("Crate~12") so the business can create a new one with the same name again.
 * Restoring removes the tag, and fails politely if the name has been taken in the meantime.
 *
 * A model lists its unique columns and their lengths: protected array $freedOnDelete = ['name' => 100];
 */
trait SoftDeletesFreeingKeys
{
    use SoftDeletes;

    public static function bootSoftDeletesFreeingKeys(): void
    {
        static::deleting(function ($model) {
            if (method_exists($model, 'isForceDeleting') && $model->isForceDeleting()) {
                return;
            }

            $changes = [];

            foreach ($model->freedOnDelete ?? [] as $column => $length) {
                $value = $model->getRawOriginal($column);

                if ($value === null) {
                    continue;
                }

                $tag = '~'.$model->getKey();
                $changes[$column] = Str::limit((string) $value, max(1, $length - strlen($tag)), '').$tag;
            }

            if ($changes) {
                DB::table($model->getTable())->where($model->getKeyName(), $model->getKey())->update($changes);
            }
        });

        static::restoring(function ($model) {
            $changes = [];

            foreach (array_keys($model->freedOnDelete ?? []) as $column) {
                $value = (string) $model->getRawOriginal($column);
                $clean = preg_replace('/~\d+$/', '', $value);

                if ($clean === $value) {
                    continue;
                }

                $taken = DB::table($model->getTable())->where('store_id', $model->store_id)->where($column, $clean)->whereNull('deleted_at')->exists();

                if ($taken) {
                    throw ValidationException::withMessages([$column => "\"{$clean}\" has since been used by another record. Rename that one first."]);
                }

                $changes[$column] = $clean;
            }

            if ($changes) {
                DB::table($model->getTable())->where($model->getKeyName(), $model->getKey())->update($changes);
                $model->forceFill($changes)->syncOriginal();
            }
        });
    }
}
