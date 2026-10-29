<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Catalogue\Models\Product;
use App\Modules\Organization\Models\Warehouse;
use App\Support\Tenancy\BelongsToStore;
use App\Support\Tenancy\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Saleable stock of one product, split by expiry date. Only StockService writes here. */
class StockBatch extends Model
{
    use BelongsToStore, BranchScoped;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'expiry_date' => 'date:Y-m-d'];
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
