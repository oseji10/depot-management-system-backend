<?php

namespace App\Support\Auditing;

use App\Models\User;
use App\Support\Tenancy\BranchScope;
use App\Support\Tenancy\StoreScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['old_values' => 'array', 'new_values' => 'array'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new StoreScope());
        static::addGlobalScope(new BranchScope());

        // Append-only.
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
