<?php

namespace App\Modules\Procurement\Models;

use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/** A scanned or photographed document (usually the manufacturer's invoice) attached to a goods receipt. */
class GoodsReceiptFile extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'goods_receipt_id', 'path', 'original_name', 'mime', 'size', 'uploaded_by'];

    protected $hidden = ['path'];
}
