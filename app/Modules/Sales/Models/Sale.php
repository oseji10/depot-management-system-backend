<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Organization\Models\Warehouse;
use App\Support\Auditing\Auditable;
use App\Support\Tenancy\BelongsToStore;
use App\Support\Tenancy\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    use Auditable, BelongsToStore, BranchScoped;

    public const CHANNELS = ['counter', 'phone', 'delivery'];

    protected $fillable = [
        'store_id', 'branch_id', 'warehouse_id', 'trip_id', 'customer_id', 'number', 'client_ref', 'channel', 'status', 'payment_status',
        'offline', 'synced_at', 'sold_at', 'subtotal', 'discount_total', 'total', 'deposit_total', 'amount_paid', 'balance_due', 'cogs_total', 'note',
        'created_by', 'voided_by', 'voided_at', 'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'sold_at' => 'datetime', 'voided_at' => 'datetime', 'synced_at' => 'datetime', 'offline' => 'boolean',
            'subtotal' => 'integer', 'discount_total' => 'integer', 'total' => 'integer', 'deposit_total' => 'integer',
            'amount_paid' => 'integer', 'balance_due' => 'integer', 'cogs_total' => 'integer',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SaleLine::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
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
