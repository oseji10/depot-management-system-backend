<?php

namespace App\Modules\Rebates\Models;

use App\Models\User;
use App\Support\Auditing\Auditable;
use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RebateCalculation extends Model
{
    use Auditable, BelongsToStore;

    protected $fillable = [
        'store_id', 'rebate_agreement_id', 'number', 'period_start', 'period_end', 'status', 'active', 'method',
        'accounting_treatment', 'cluster_name', 'cluster_volume', 'cluster_from', 'cluster_to', 'cluster_overridden',
        'override_reason', 'total_eligible_value', 'total_rebate', 'warnings', 'created_by', 'approved_by', 'approved_at',
        'accrual_journal_id', 'voided_by', 'voided_at', 'void_reason', 'settlement_method', 'settled_on',
        'settlement_reference', 'settled_by', 'settlement_journal_id',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date:Y-m-d', 'period_end' => 'date:Y-m-d', 'cluster_from' => 'date:Y-m-d', 'cluster_to' => 'date:Y-m-d',
            'settled_on' => 'date:Y-m-d', 'approved_at' => 'datetime', 'voided_at' => 'datetime',
            'cluster_volume' => 'float', 'cluster_overridden' => 'boolean', 'active' => 'boolean',
            'total_eligible_value' => 'integer', 'total_rebate' => 'integer', 'warnings' => 'array',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RebateCalculationLine::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(RebateCalculationSource::class);
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(RebateAgreement::class, 'rebate_agreement_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
