<?php

namespace App\Modules\Containers\Models;

use App\Support\Tenancy\BelongsToStore;
use App\Support\Tenancy\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only. A correction is a new row that undoes the old one. */
class ContainerMovement extends Model
{
    use BelongsToStore, BranchScoped;

    public const UPDATED_AT = null;

    protected $fillable = [
        'store_id', 'branch_id', 'container_type_id', 'warehouse_id', 'party_type', 'party_id', 'kind',
        'qty_party', 'qty_depot', 'deposit', 'source_type', 'source_id', 'note', 'user_id',
    ];

    protected function casts(): array
    {
        return ['qty_party' => 'integer', 'qty_depot' => 'integer', 'deposit' => 'integer'];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ContainerType::class, 'container_type_id');
    }
}
