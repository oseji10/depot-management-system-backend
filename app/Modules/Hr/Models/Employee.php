<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use App\Support\Auditing\Auditable;
use App\Support\Tenancy\BelongsToStore;
use App\Support\Tenancy\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use Auditable, BelongsToStore, BranchScoped;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['basic_salary' => 'integer', 'hired_on' => 'date:Y-m-d', 'left_on' => 'date:Y-m-d'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payItems(): HasMany
    {
        return $this->hasMany(EmployeePayItem::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(StaffCharge::class);
    }
}
