<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use App\Modules\Catalogue\Models\Product;
use App\Support\Auditing\Auditable;
use App\Support\Tenancy\BelongsToStore;
use App\Support\Tenancy\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money an employee owes the business (a damaged product, a shortage), recovered from their salary. */
class StaffCharge extends Model
{
    use Auditable, BelongsToStore, BranchScoped;

    public const REASONS = ['damage' => 'Damaged product', 'shortage' => 'Shortage', 'loss' => 'Lost item', 'other' => 'Other'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'recovered' => 'integer', 'unit_amount' => 'integer', 'installments' => 'integer', 'decided_at' => 'datetime', 'incident_on' => 'date:Y-m-d'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function outstanding(): int
    {
        return max(0, $this->amount - $this->recovered);
    }

    /** What payroll takes in one month: the total spread over the instalments, never more than what is left. */
    public function monthlyDeduction(): int
    {
        return min($this->outstanding(), (int) ceil($this->amount / max(1, $this->installments)));
    }
}
