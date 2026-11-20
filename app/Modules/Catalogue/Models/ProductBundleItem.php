<?php

namespace App\Modules\Catalogue\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One product inside a bundle, e.g. 2 crates of Coke 50cl (= 48 bottles). */
class ProductBundleItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'base_quantity' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withoutGlobalScopes();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class)->withoutGlobalScopes();
    }
}
