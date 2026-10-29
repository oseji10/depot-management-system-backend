<?php

namespace App\Modules\FieldSales\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToStore;
use App\Support\Tenancy\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Something a phone sent that the server could not accept. It waits here for a manager to retry or dismiss. */
class SyncIssue extends Model
{
    use BelongsToStore, BranchScoped;

    protected $fillable = [
        'store_id', 'branch_id', 'user_id', 'trip_id', 'kind', 'client_ref', 'payload', 'error', 'attempts',
        'status', 'resolved_by', 'resolved_at', 'resolution',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array', 'resolved_at' => 'datetime', 'attempts' => 'integer'];
    }

    public function rep(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }
}
