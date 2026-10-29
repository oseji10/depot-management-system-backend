<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Catalogue\Models\Product;
use App\Modules\Organization\Models\Warehouse;
use App\Support\Tenancy\BelongsToStore;
use App\Support\Tenancy\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only. Corrections are new movements, never edits. */
class StockMovement extends Model
{
    use BelongsToStore, BranchScoped;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'occurred_at' => 'datetime', 'unit_cost' => 'decimal:4'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
