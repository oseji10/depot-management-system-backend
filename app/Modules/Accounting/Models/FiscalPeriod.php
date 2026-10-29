<?php

namespace App\Modules\Accounting\Models;

use App\Support\Auditing\Auditable;
use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class FiscalPeriod extends Model
{
    use Auditable, BelongsToStore;

    protected $fillable = ['store_id', 'starts_on', 'ends_on', 'status', 'closed_by', 'closed_at'];

    protected function casts(): array
    {
        return ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'closed_at' => 'datetime'];
    }
}
