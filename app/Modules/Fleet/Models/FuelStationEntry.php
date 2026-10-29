<?php

namespace App\Modules\Fleet\Models;

use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class FuelStationEntry extends Model
{
    use BelongsToStore;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'entry_date' => 'date'];
    }
}
