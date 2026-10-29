<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Catalogue\Models\Product;
use App\Modules\Catalogue\Models\Unit;
use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Each posted line is a stable, traceable unit that rebate claims will later reference. */
class GoodsReceiptLine extends Model
{
    use BelongsToStore;

    public $timestamps = false;

    protected $fillable = [
        'store_id', 'goods_receipt_id', 'purchase_order_line_id', 'product_id', 'unit_id', 'factor',
        'delivered', 'accepted', 'damaged', 'rejected', 'unit_cost', 'reason',
        'batch_no', 'expiry_date',
    ];

    protected function casts(): array
    {
        return ['expiry_date' => 'date:Y-m-d', 'unit_cost' => 'decimal:2'];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
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
