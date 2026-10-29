<?php

namespace App\Modules\FieldSales\Models;

use App\Modules\Catalogue\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TripLoadLine extends Model
{
    protected $fillable = ['trip_load_id', 'product_id', 'status', 'quantity'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
