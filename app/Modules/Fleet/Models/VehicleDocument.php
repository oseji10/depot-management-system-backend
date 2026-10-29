<?php

namespace App\Modules\Fleet\Models;

use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class VehicleDocument extends Model
{
    use BelongsToStore;

    public const KINDS = ['insurance' => 'Insurance', 'roadworthiness' => 'Roadworthiness', 'vehicle_license' => 'Vehicle licence', 'proof_of_ownership' => 'Proof of ownership', 'other' => 'Other'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['issued_on' => 'date:Y-m-d', 'expires_on' => 'date:Y-m-d'];
    }
}
