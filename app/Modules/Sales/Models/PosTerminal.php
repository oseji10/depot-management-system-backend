<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Organization\Models\Branch;
use App\Support\Auditing\Auditable;
use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A card machine. Card payments are tied to the one that took them, so its takings can be matched to the provider's settlement. */
class PosTerminal extends Model
{
    use Auditable, BelongsToStore;

    protected $fillable = [
        'store_id', 'branch_id', 'assigned_user_id', 'account_id', 'name', 'provider', 'terminal_id', 'merchant_id', 'serial_number', 'notes', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /** Terminals a user may take a card payment on at a branch: that branch's (or any-branch) machines that are not carried by someone else. */
    public function scopeUsableAt(Builder $q, ?int $branchId, int $userId): Builder
    {
        return $q->where('is_active', true)
            ->when($branchId, fn ($q) => $q->where(fn ($w) => $w->whereNull('branch_id')->orWhere('branch_id', $branchId)))
            ->where(fn ($w) => $w->whereNull('assigned_user_id')->orWhere('assigned_user_id', $userId));
    }
}
