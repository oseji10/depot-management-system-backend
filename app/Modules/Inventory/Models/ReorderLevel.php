<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Catalogue\Models\Product;
use App\Modules\Organization\Models\Warehouse;
use App\Support\Auditing\Auditable;
use App\Support\Tenancy\BelongsToStore;
use App\Support\Tenancy\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReorderLevel extends Model
{
    use Auditable, BelongsToStore, BranchScoped;

    protected $fillable = ['store_id', 'branch_id', 'warehouse_id', 'product_id', 'min_quantity'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
