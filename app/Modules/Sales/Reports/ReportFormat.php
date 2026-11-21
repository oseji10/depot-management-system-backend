<?php

namespace App\Modules\Sales\Reports;

/** Small formatting helpers shared by the report PDF and email. Amounts arrive in kobo. */
class ReportFormat
{
    public static function money(int $kobo): string
    {
        $naira = $kobo / 100;

        return '₦'.number_format($naira, fmod($naira, 1.0) == 0.0 ? 0 : 2);
    }

    public static function number(int|float $n): string
    {
        return number_format($n, is_float($n) && fmod($n, 1.0) != 0.0 ? 1 : 0);
    }

    /** "+₦12,000 vs yesterday" with a direction: up, down or same. */
    public static function delta(int $now, int $before, bool $money = true): array
    {
        $d = $now - $before;
        $abs = $money ? self::money(abs($d)) : self::number(abs($d));
        $pct = $before > 0 ? ' ('.($d >= 0 ? '+' : '−').self::number(round(abs($d) / $before * 100, 1)).'%)' : '';

        return ['dir' => $d > 0 ? 'up' : ($d < 0 ? 'down' : 'same'), 'text' => ($d > 0 ? '+' : ($d < 0 ? '−' : '±')).$abs.$pct];
    }
}
