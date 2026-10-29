<?php

namespace App\Modules\Fleet\Models;

use App\Models\User;
use App\Modules\FieldSales\Models\Trip;
use App\Support\Auditing\Auditable;
use App\Support\Tenancy\BelongsToStore;
use App\Support\Tenancy\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FuelVoucher extends Model
{
    use Auditable, BelongsToStore, BranchScoped;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'amount_used' => 'integer', 'litres' => 'float', 'redeemed_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(FuelStation::class, 'fuel_station_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
