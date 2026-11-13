<?php

namespace App\Modules\Catalogue\Services;

use App\Modules\Catalogue\Models\Unit;

/** Variations are named by typing: an existing name (any capitalisation) is reused, a new one becomes a unit. */
class UnitNames
{
    public static function find(int $storeId, string $name): int
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        abort_if($name === '', 422, 'Every variation needs a name.');

        $unit = Unit::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();

        if (! $unit) {
            $base = mb_substr(preg_replace('/[^a-z0-9]/', '', mb_strtolower($name)) ?: 'unit', 0, 8);
            $abbr = $base;

            for ($i = 2; Unit::withTrashed()->where('store_id', $storeId)->where('abbreviation', $abbr)->exists(); $i++) {
                $abbr = mb_substr($base, 0, 10 - strlen((string) $i)).$i;
            }

            $unit = Unit::create(['store_id' => $storeId, 'name' => mb_substr($name, 0, 50), 'abbreviation' => $abbr]);
        }

        return $unit->id;
    }
}
