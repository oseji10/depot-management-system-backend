<?php

namespace App\Modules\FieldSales\Models;

use App\Modules\Catalogue\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TripLine extends Model
{
    protected $fillable = ['trip_id', 'product_id', 'issued', 'returned', 'damaged', 'shortage'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
