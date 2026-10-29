<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Catalogue\Models\Product;
use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockAdjustmentLine extends Model
{
    use BelongsToStore;

    public $timestamps = false;

    protected $fillable = ['store_id', 'adjustment_id', 'product_id', 'status', 'to_status', 'quantity', 'unit_cost', 'batch_no', 'expiry_date', 'stock_batch_id'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
