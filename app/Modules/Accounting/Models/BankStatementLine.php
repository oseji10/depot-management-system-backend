<?php

namespace App\Modules\Accounting\Models;

use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankStatementLine extends Model
{
    use BelongsToStore;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['txn_date' => 'date'];
    }

    public function matches(): HasMany
    {
        return $this->hasMany(BankStatementMatch::class);
    }
}
