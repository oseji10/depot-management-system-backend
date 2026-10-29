<?php

namespace App\Modules\Sales\Models;

use App\Modules\Catalogue\Models\Product;
use App\Modules\Catalogue\Models\Unit;
use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleLine extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'sale_id', 'product_id', 'unit_id', 'factor', 'quantity', 'list_price', 'unit_price', 'line_total', 'cost_total',
        'mix_layout_id', 'mix_group', 'mix_label'];

    protected function casts(): array
    {
        return [
            'factor' => 'integer', 'quantity' => 'integer', 'list_price' => 'integer',
            'unit_price' => 'integer', 'line_total' => 'integer', 'cost_total' => 'integer',
        ];
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
