<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Catalogue\Models\Product;
use App\Modules\Catalogue\Models\Unit;
use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderLine extends Model
{
    use BelongsToStore;

    public $timestamps = false;

    protected $fillable = ['store_id', 'purchase_order_id', 'product_id', 'unit_id', 'factor', 'quantity', 'unit_cost'];

    protected function casts(): array
    {
        return ['unit_cost' => 'decimal:2'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
