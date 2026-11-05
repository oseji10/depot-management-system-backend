<?php

namespace App\Modules\Fleet\Models;

use App\Support\Auditing\Auditable;
use App\Support\Tenancy\BelongsToStore;
use App\Support\Concerns\SoftDeletesFreeingKeys;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FuelStation extends Model
{
    use Auditable, BelongsToStore, SoftDeletesFreeingKeys;

    /** Unique values released when the record is deleted, with their column lengths. */
    protected array $freedOnDelete = ['name' => 100];

    protected $fillable = ['store_id', 'name', 'location', 'phone', 'price_per_litre', 'is_active'];

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

    /** Litres left with the station. Stations that were topped up before litres were tracked have none recorded. */
    public function litresBalance(): float
    {
        return round((float) $this->entries()->sum('litres'), 2);
    }

    /** Has this station ever been topped up in litres? If not, litres are not enforced when a voucher is issued. */
    public function tracksLitres(): bool
    {
        return $this->entries()->whereNotNull('litres')->exists();
    }
}
