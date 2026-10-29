<?php

namespace App\Modules\Rebates\Models;

use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class RebateCalculationLine extends Model
{
    use BelongsToStore;

    public $timestamps = false;

    protected $fillable = [
        'store_id', 'rebate_calculation_id', 'rebate_category_id', 'category_name', 'cases', 'eligible_value',
        'tier_from', 'tier_rate', 'effective_rate', 'rebate', 'no_tier',
    ];

    protected function casts(): array
    {
        return [
            'cases' => 'float', 'eligible_value' => 'integer', 'tier_from' => 'integer', 'tier_rate' => 'float',
            'effective_rate' => 'float', 'rebate' => 'integer', 'no_tier' => 'boolean',
        ];
    }
}
