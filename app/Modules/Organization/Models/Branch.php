<?php

namespace App\Modules\Organization\Models;

use App\Models\User;
use App\Support\Auditing\Auditable;
use App\Support\Tenancy\BelongsToStore;
use App\Support\Tenancy\BranchScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model
{
    use Auditable, BelongsToStore, SoftDeletes;

    protected $fillable = [
        'store_id', 'name', 'code', 'address', 'city', 'state',
        'phone', 'email', 'is_head_office', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_head_office' => 'boolean', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        // Branch-level users only ever see their own branch row.
        static::addGlobalScope(new BranchScope('id'));
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
