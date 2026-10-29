<?php

namespace App\Modules\Expenses\Models;

use App\Models\User;
use App\Modules\FieldSales\Models\Trip;
use App\Support\Auditing\Auditable;
use App\Support\Tenancy\BelongsToStore;
use App\Support\Tenancy\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money the depot spent. Posts to the books the moment it is saved; a mistake is voided, never edited. */
class Expense extends Model
{
    use Auditable, BelongsToStore, BranchScoped;

    public const METHODS = ['cash', 'bank', 'imprest'];

    protected $fillable = [
        'store_id', 'branch_id', 'number', 'category_id', 'expense_date', 'amount', 'method', 'paid_to', 'description', 'trip_id',
        'client_ref', 'status', 'offline', 'created_by', 'voided_by', 'voided_at', 'void_reason',
    ];

    protected function casts(): array
    {
        return ['expense_date' => 'date', 'amount' => 'integer', 'offline' => 'boolean', 'voided_at' => 'datetime'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
