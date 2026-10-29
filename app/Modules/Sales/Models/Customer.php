<?php

namespace App\Modules\Sales\Models;

use App\Support\Auditing\Auditable;
use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Customer extends Model
{
    use Auditable, BelongsToStore;

    public const TYPES = ['retail', 'wholesale'];
    public const EMPTIES_MODES = ['deposit', 'balance', 'none'];

    protected $fillable = ['store_id', 'client_ref', 'name', 'type', 'phone', 'email', 'address', 'credit_limit', 'notes', 'is_active', 'empties_mode'];

    protected function casts(): array
    {
        return ['credit_limit' => 'integer', 'is_active' => 'boolean'];
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /**
     * What each customer owes across the WHOLE depot, in kobo. Deliberately ignores branch scoping: credit is a depot
     * decision, and a customer must not be able to owe the limit at every branch.
     *
     * @param  array<int>  $ids
     * @return array<int, int>
     */
    public static function outstanding(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('sales')->whereIn('customer_id', $ids)->where('status', 'completed')->where('balance_due', '>', 0)
            ->groupBy('customer_id')->selectRaw('customer_id, SUM(balance_due) AS due')
            ->pluck('due', 'customer_id')->map(fn ($v) => (int) $v)->all();
    }
}
