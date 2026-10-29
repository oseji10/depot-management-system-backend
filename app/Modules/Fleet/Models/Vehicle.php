<?php

namespace App\Modules\Fleet\Models;

use App\Models\User;
use App\Support\Auditing\Auditable;
use App\Support\Tenancy\BelongsToStore;
use App\Support\Tenancy\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehicle extends Model
{
    use Auditable, BelongsToStore, BranchScoped;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['year' => 'integer', 'capacity_crates' => 'integer', 'odometer' => 'integer'];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'default_driver_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(VehicleDocument::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(VehicleService::class);
    }
}
