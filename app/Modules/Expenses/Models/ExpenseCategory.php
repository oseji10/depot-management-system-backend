<?php

namespace App\Modules\Expenses\Models;

use App\Modules\Accounting\Models\Account;
use App\Support\Auditing\Auditable;
use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpenseCategory extends Model
{
    use Auditable, BelongsToStore;

    protected $fillable = ['store_id', 'name', 'account_id', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
