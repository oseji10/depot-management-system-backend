<?php

namespace App\Modules\Sales\Models;

use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class ProformaLine extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'proforma_id', 'product_id', 'unit_id', 'description', 'factor', 'quantity', 'unit_price', 'list_price', 'line_total'];
}
