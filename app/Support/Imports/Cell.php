<?php

namespace App\Support\Imports;

/** Reading values out of a spreadsheet row the browser sent, whatever shape Excel left them in. */
class Cell
{
    public static function text(array $row, string $key, int $max = 255): ?string
    {
        $v = $row[$key] ?? null;

        if ($v === null || is_array($v)) {
            return null;
        }

        $v = trim(preg_replace('/\s+/', ' ', (string) $v));

        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    /** Naira in the sheet -> a float, or null when blank or not a number. */
    public static function number(array $row, string $key): ?float
    {
        $v = $row[$key] ?? null;

        if ($v === null || $v === '') {
            return null;
        }

        $clean = is_numeric($v) ? $v : preg_replace('/[^0-9.\-]/', '', (string) $v);

        return is_numeric($clean) ? (float) $clean : null;
    }

    public static function kobo(array $row, string $key): int
    {
        return max(0, (int) round((self::number($row, $key) ?? 0) * 100));
    }

    /** "2026-10-29 17:14:27" as sent by the browser, else null. */
    public static function dateTime(array $row, string $key): ?string
    {
        $v = self::text($row, $key);

        if ($v === null) {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($v)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }
}
