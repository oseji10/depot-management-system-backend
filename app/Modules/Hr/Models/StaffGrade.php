<?php

namespace App\Modules\Hr\Models;

use App\Support\Auditing\Auditable;
use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A staff grade (e.g. Senior staff): its overtime rate and how Sunday work is paid. */
class StaffGrade extends Model
{
    use Auditable, BelongsToStore;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['overtime_rate' => 'integer', 'sunday_divisor' => 'integer', 'is_active' => 'boolean'];
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'grade_id');
    }
}
