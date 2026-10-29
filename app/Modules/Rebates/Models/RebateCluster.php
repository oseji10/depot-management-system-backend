<?php

namespace App\Modules\Rebates\Models;

use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RebateCluster extends Model
{
    use BelongsToStore;

    public $timestamps = false;

    protected $fillable = ['store_id', 'rebate_agreement_id', 'name', 'volume_above', 'sort_order'];

    public function tiers(): HasMany
    {
        return $this->hasMany(RebateTier::class)->orderBy('rebate_category_id')->orderBy('from_cases');
    }
}
