<?php

namespace App\Modules\Fleet\Models;

use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class VehicleService extends Model
{
    use BelongsToStore;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['serviced_on' => 'date:Y-m-d', 'cost' => 'integer', 'odometer' => 'integer'];
    }
}
