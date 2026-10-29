<?php

namespace App\Modules\Hr\Models;

use App\Support\Auditing\Auditable;
use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/** An allowance or deduction the business defines, e.g. "Transport allowance" or "Pension 8%". */
class PayItem extends Model
{
    use Auditable, BelongsToStore;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'integer', 'apply_to_all' => 'boolean', 'is_active' => 'boolean'];
    }

    /** What this item comes to for a given basic salary. A person's own override replaces the item's value. */
    public function amountFor(int $basic, ?int $override = null): int
    {
        $value = $override ?? $this->value;

        return $this->calc === 'percent_of_basic' ? (int) round($basic * $value / 10000) : $value;
    }
}
