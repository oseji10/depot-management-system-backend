<?php

namespace App\Modules\Rebates\Models;

use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class RebateTier extends Model
{
    use BelongsToStore;

    public $timestamps = false;

    protected $fillable = ['store_id', 'rebate_cluster_id', 'rebate_category_id', 'from_cases', 'rate'];

    protected function casts(): array
    {
        return ['from_cases' => 'integer', 'rate' => 'float'];
    }
}
