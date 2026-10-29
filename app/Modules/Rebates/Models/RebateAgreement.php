<?php

namespace App\Modules\Rebates\Models;

use App\Support\Auditing\Auditable;
use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RebateAgreement extends Model
{
    use Auditable, BelongsToStore;

    public const METHODS = ['all_units', 'marginal'];
    public const TREATMENTS = ['income', 'cogs_reduction'];

    protected $fillable = ['store_id', 'name', 'effective_from', 'effective_to', 'method', 'accounting_treatment', 'is_active', 'note'];

    protected function casts(): array
    {
        return ['effective_from' => 'date:Y-m-d', 'effective_to' => 'date:Y-m-d', 'is_active' => 'boolean'];
    }

    public function clusters(): HasMany
    {
        return $this->hasMany(RebateCluster::class)->orderBy('sort_order');
    }
}
