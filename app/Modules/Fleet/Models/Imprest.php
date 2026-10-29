<?php

namespace App\Modules\Fleet\Models;

use App\Models\User;
use App\Modules\FieldSales\Models\Trip;
use App\Support\Auditing\Auditable;
use App\Support\Tenancy\BelongsToStore;
use App\Support\Tenancy\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Running money given to a driver for one trip. */
class Imprest extends Model
{
    use Auditable, BelongsToStore, BranchScoped;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'voided_at' => 'datetime'];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
