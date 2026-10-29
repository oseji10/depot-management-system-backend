<?php

namespace App\Modules\Procurement\Models;

use App\Models\User;
use App\Modules\Organization\Models\Warehouse;
use App\Support\Auditing\Auditable;
use App\Support\Tenancy\BelongsToStore;
use App\Support\Tenancy\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    use Auditable, BelongsToStore, BranchScoped;

    protected $fillable = [
        'store_id', 'branch_id', 'warehouse_id', 'supplier_id', 'number', 'status',
        'order_date', 'expected_date', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return ['order_date' => 'date:Y-m-d', 'expected_date' => 'date:Y-m-d'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
