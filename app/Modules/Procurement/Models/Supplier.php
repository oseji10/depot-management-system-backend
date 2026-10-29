<?php

namespace App\Modules\Procurement\Models;

use App\Support\Auditing\Auditable;
use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use Auditable, BelongsToStore;

    protected $fillable = [
        'store_id', 'name', 'code', 'contact_name', 'phone', 'email', 'address',
        'payment_terms_days', 'is_manufacturer', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_manufacturer' => 'boolean', 'is_active' => 'boolean', 'payment_terms_days' => 'integer'];
    }
}
