<?php

namespace App\Modules\Fleet\Models;

use App\Support\Auditing\Auditable;
use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FuelStation extends Model
{
    use Auditable, BelongsToStore;

    protected $fillable = ['store_id', 'name', 'location', 'phone', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(FuelStationEntry::class);
    }

    /** Prepaid money left with the station (kobo). */
    public function balance(): int
    {
        return (int) $this->entries()->sum('amount');
    }

    /** What is left once vouchers already handed out but not yet used are set aside. */
    public function committed(): int
    {
        return (int) FuelVoucher::withoutGlobalScopes()->where('fuel_station_id', $this->id)->where('status', 'issued')->sum('amount');
    }
}
