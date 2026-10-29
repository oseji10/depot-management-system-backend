<?php

namespace App\Modules\Hr\Models;

use App\Support\Auditing\Auditable;
use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRun extends Model
{
    use Auditable, BelongsToStore;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }
}
