<?php

namespace App\Models;

use App\Modules\Organization\Models\Branch;
use App\Modules\Organization\Models\Store;
use App\Support\Auditing\Auditable;
use App\Support\Tenancy\AccessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use Auditable, HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected $guard_name = 'web';

    // is_super_admin is deliberately NOT fillable.
    protected $fillable = ['name', 'email', 'phone', 'password', 'store_id', 'branch_id', 'is_active'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'is_active' => 'boolean',
            'email_notifications' => 'boolean',
            'email_kinds' => 'array',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Users have no global scope (the auth guard loads them, which would recurse),
     * so every user query made on behalf of an actor MUST go through this scope.
     */
    public function scopeVisibleToActor(Builder $query): Builder
    {
        $access = app(AccessScope::class);

        if ($access->storeId() !== null) {
            $query->where('users.store_id', $access->storeId());
        } elseif (! $access->isSuperAdmin()) {
            $query->whereRaw('1 = 0');
        }

        if ($access->branchId() !== null) {
            $query->where('users.branch_id', $access->branchId());
        }

        return $query;
    }
}
