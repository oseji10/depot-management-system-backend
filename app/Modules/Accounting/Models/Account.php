<?php

namespace App\Modules\Accounting\Models;

use App\Support\Auditing\Auditable;
use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use Auditable, BelongsToStore;

    public const TYPES = ['asset', 'liability', 'equity', 'revenue', 'other_income', 'cogs', 'expense'];

    /** Types whose natural balance is a debit. Everything else is a credit balance. */
    public const DEBIT_TYPES = ['asset', 'cogs', 'expense'];

    protected $fillable = ['store_id', 'code', 'name', 'type', 'role', 'is_system', 'is_active'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'is_active' => 'boolean'];
    }

    public function isDebitNormal(): bool
    {
        return in_array($this->type, self::DEBIT_TYPES, true);
    }
}
