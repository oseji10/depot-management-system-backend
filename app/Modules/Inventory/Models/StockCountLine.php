<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Catalogue\Models\Product;
use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCountLine extends Model
{
    use BelongsToStore;

    public $timestamps = false;

    protected $fillable = ['store_id', 'count_id', 'product_id', 'status', 'expected_quantity', 'counted_quantity', 'posted_variance'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
