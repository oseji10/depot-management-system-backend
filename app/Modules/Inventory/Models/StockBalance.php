<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Catalogue\Models\Product;
use App\Modules\Organization\Models\Warehouse;
use App\Support\Tenancy\BelongsToStore;
use App\Support\Tenancy\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Derived from the ledger. Only StockService writes here. */
class StockBalance extends Model
{
    use BelongsToStore, BranchScoped;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
