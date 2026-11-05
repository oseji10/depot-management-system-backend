<?php

namespace App\Modules\Procurement\Models;

use App\Support\Auditing\Auditable;
use App\Support\Tenancy\BelongsToStore;
use App\Support\Concerns\SoftDeletesFreeingKeys;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use Auditable, BelongsToStore, SoftDeletesFreeingKeys;

    /** Unique values released when the record is deleted, with their column lengths. */
    protected array $freedOnDelete = ['code' => 20];

    protected $fillable = [
        'store_id', 'name', 'code', 'contact_name', 'phone', 'email', 'address',
        'payment_terms_days', 'is_manufacturer', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_manufacturer' => 'boolean', 'is_active' => 'boolean', 'payment_terms_days' => 'integer'];
    }
}
