<?php

namespace App\Modules\Inventory\Support;

class StockStatus
{
    public const SALEABLE = 'saleable';
    public const DAMAGED = 'damaged';
    public const EXPIRED = 'expired';

    public const ALL = [self::SALEABLE, self::DAMAGED, self::EXPIRED];
}
