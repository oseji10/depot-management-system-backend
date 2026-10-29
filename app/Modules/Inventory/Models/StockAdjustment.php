<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Organization\Models\Warehouse;
use App\Support\Auditing\Auditable;
use App\Support\Tenancy\BelongsToStore;
use App\Support\Tenancy\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockAdjustment extends Model
{
    use Auditable, BelongsToStore, BranchScoped;

    /** reason => which direction of quantity it allows */
    public const REASONS = [
        'adjustment' => [
            'opening_balance' => 'positive', 'found' => 'positive',
            'loss' => 'negative', 'theft' => 'negative', 'expiry_write_off' => 'negative', 'damage_write_off' => 'negative',
            'correction' => 'any',
        ],
        'reclassify' => ['damaged' => 'any', 'expired' => 'any', 'recovered' => 'any'],
    ];

    protected $fillable = [
        'store_id', 'branch_id', 'warehouse_id', 'number', 'kind', 'reason', 'status', 'note',
        'created_by', 'approved_by', 'decided_at', 'decision_note',
    ];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockAdjustmentLine::class, 'adjustment_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
