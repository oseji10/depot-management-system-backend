<?php

namespace App\Modules\Rebates\Models;

use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class RebateCalculationSource extends Model
{
    use BelongsToStore;

    public $timestamps = false;

    protected $fillable = [
        'store_id', 'rebate_calculation_id', 'goods_receipt_line_id', 'product_id', 'rebate_category_id', 'cases', 'value', 'cases_assumed',
    ];

    protected function casts(): array
    {
        return ['cases' => 'float', 'value' => 'integer', 'cases_assumed' => 'boolean'];
    }
}
