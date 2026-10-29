<?php

namespace App\Support;

/** All accounting arithmetic is done in integer kobo. Floats never touch the books. */
class Money
{
    public static function kobo(float|int|string $naira): int
    {
        return (int) round(((float) $naira) * 100);
    }

    public static function naira(int $kobo): string
    {
        return number_format($kobo / 100, 2, '.', '');
    }
}
