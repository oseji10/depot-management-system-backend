<?php

namespace App\Modules\Sales\Models;

use App\Support\Auditing\Auditable;
use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A price quotation for a customer. It reserves no stock and records no money until it is turned into a sale. */
class Proforma extends Model
{
    use Auditable, BelongsToStore, SoftDeletes;

    protected $fillable = [
        'store_id', 'branch_id', 'number', 'status', 'customer_id', 'customer_name', 'customer_phone', 'customer_email', 'customer_address',
        'issued_on', 'valid_until', 'subtotal', 'discount', 'delivery_fee', 'total', 'notes', 'terms', 'converted_sale_id', 'created_by',
    ];

    protected $appends = ['is_expired'];

    protected function casts(): array
    {
        return ['issued_on' => 'date:Y-m-d', 'valid_until' => 'date:Y-m-d'];
    }

    /** Still open (draft, sent or accepted) but past its "valid until" date. */
    public function getIsExpiredAttribute(): bool
    {
        return in_array($this->status, ['draft', 'sent', 'accepted'], true) && $this->valid_until !== null && $this->valid_until->lt(now()->startOfDay());
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ProformaLine::class)->orderBy('id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }
}
