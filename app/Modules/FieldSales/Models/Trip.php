<?php

namespace App\Modules\FieldSales\Models;

use App\Models\User;
use App\Modules\Organization\Models\Warehouse;
use App\Support\Auditing\Auditable;
use App\Support\Tenancy\BelongsToStore;
use App\Support\Tenancy\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One outing: stock issued to a salesperson, what they sold, and what came back. */
class Trip extends Model
{
    use Auditable, BelongsToStore, BranchScoped;

    protected $fillable = [
        'store_id', 'branch_id', 'number', 'rep_id', 'van_warehouse_id', 'vehicle_id', 'status', 'opened_at', 'opened_by',
        'closed_at', 'closed_by', 'cash_expected', 'cash_handed_in', 'cash_variance', 'note', 'close_note',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime', 'closed_at' => 'datetime',
            'cash_expected' => 'integer', 'cash_handed_in' => 'integer', 'cash_variance' => 'integer',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(TripLine::class);
    }

    public function loads(): HasMany
    {
        return $this->hasMany(TripLoad::class);
    }

    public function rep(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rep_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Fleet\Models\Vehicle::class);
    }

    public function van(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'van_warehouse_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
