<?php

namespace App\Modules\FieldSales\Models;

use App\Models\User;
use App\Modules\Organization\Models\Warehouse;
use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Stock going onto the van (issue) or coming off it (return). Stock movements reference these. */
class TripLoad extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'trip_id', 'kind', 'warehouse_id', 'user_id', 'note'];

    public function lines(): HasMany
    {
        return $this->hasMany(TripLoadLine::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class)->withoutGlobalScopes();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
