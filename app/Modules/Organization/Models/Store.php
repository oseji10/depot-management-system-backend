<?php

namespace App\Modules\Organization\Models;

use App\Models\User;
use App\Support\Auditing\Auditable;
use App\Support\Tenancy\StoreScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** The tenant. Non-super users can only ever see their own row. */
class Store extends Model
{
    use Auditable;

    protected $fillable = [
        'name', 'slug', 'legal_name', 'email', 'phone', 'address', 'state',
        'country', 'currency', 'timezone', 'is_active', 'settings', 'logo_path',
    ];

    protected $hidden = ['logo_path'];

    protected $appends = ['logo_url'];

    /** Relative API path of the business logo; the version changes when it is replaced so browsers refetch. */
    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo_path ? '/business/logo?v='.($this->updated_at?->timestamp ?? 0) : null;
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'settings' => 'array'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new StoreScope('id'));
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
