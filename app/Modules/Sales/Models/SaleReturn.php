<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Support\Auditing\Auditable;
use App\Support\Tenancy\BelongsToStore;
use App\Support\Tenancy\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleReturn extends Model
{
    use Auditable, BelongsToStore, BranchScoped;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['returned_at' => 'datetime'];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SaleReturnLine::class);
    }
}
