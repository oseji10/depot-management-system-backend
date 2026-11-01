<?php

namespace App\Modules\Sales\Models;

use App\Modules\Catalogue\Models\Product;
use App\Modules\Catalogue\Models\Unit;
use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleReturnLine extends Model
{
    use BelongsToStore;

    protected $guarded = [];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
