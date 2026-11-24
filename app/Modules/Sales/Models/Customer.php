<?php

namespace App\Modules\Sales\Models;

use App\Support\Auditing\Auditable;
use App\Support\Tenancy\BelongsToStore;
use App\Support\Concerns\SoftDeletesFreeingKeys;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Customer extends Model
{
    use Auditable, BelongsToStore, SoftDeletesFreeingKeys;

    /** Unique values released when the record is deleted, with their column lengths. */
    protected array $freedOnDelete = ['phone' => 30];

    public const TYPES = ['retail', 'wholesale'];
    public const EMPTIES_MODES = ['deposit', 'balance', 'none'];

    protected $fillable = ['store_id', 'client_ref', 'name', 'first_name', 'last_name', 'business_name', 'type', 'phone', 'email', 'address', 'credit_limit', 'notes', 'is_active', 'empties_mode'];

    /** Words that mark a name as a business rather than a person (used to sort out names typed before the split). */
    public const BUSINESS_WORDS = ['ventures', 'venture', 'enterprise', 'enterprises', 'enterprice', 'stores', 'store', 'shop', 'supermarket', 'mart',
        'ltd', 'limited', 'nig', 'nigeria', 'global', 'company', 'co', 'int\'l', 'international', 'investment', 'investments', 'industries',
        'hotel', 'hotels', 'bar', 'lounge', 'restaurant', 'eatery', 'kitchen', 'canteen', 'suites', 'resort', 'club', 'church', 'school', 'college',
        'academy', 'hospital', 'clinic', 'pharmacy', 'services', 'concept', 'concepts', 'trading', 'traders', 'distributors', 'distribution',
        'depot', 'plaza', 'mall', 'nursery', 'foods', 'drinks', 'beverages', 'catering', 'events', 'agency', 'associates', 'group', 'plc', '&'];

    protected static function booted(): void
    {
        // `name` is the display name everything else (receipts, searches, reports) reads. It is built from the parts:
        // the business name when there is one, otherwise "First Last". Older callers that only send `name` get it split.
        static::saving(function (Customer $c) {
            $parts = ['first_name', 'last_name', 'business_name'];
            $hasParts = collect($parts)->contains(fn ($f) => filled($c->{$f}));

            if (! $hasParts && filled($c->name)) {
                foreach (self::splitName($c->name) as $field => $value) {
                    $c->{$field} = $value;
                }
            }

            foreach ($parts as $f) {
                $c->{$f} = filled($c->{$f}) ? mb_substr(trim(preg_replace('/\s+/', ' ', $c->{$f})), 0, $f === 'business_name' ? 150 : 80) : null;
            }

            $display = $c->business_name ?: trim(($c->first_name ?? '').' '.($c->last_name ?? ''));
            if ($display !== '') {
                $c->name = mb_substr($display, 0, 150);
            }
        });
    }

    /**
     * Split a single typed name into first / last name, or treat it as a business name.
     *
     * @return array{first_name: ?string, last_name: ?string, business_name: ?string}
     */
    public static function splitName(string $name): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        $words = preg_split('/[\s,.()\/-]+/', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY);

        if (array_intersect($words, self::BUSINESS_WORDS) !== [] || str_contains($name, '&')) {
            return ['first_name' => null, 'last_name' => null, 'business_name' => mb_substr($name, 0, 150)];
        }

        $bits = explode(' ', $name, 2);

        return ['first_name' => mb_substr($bits[0], 0, 80), 'last_name' => isset($bits[1]) ? mb_substr($bits[1], 0, 80) : null, 'business_name' => null];
    }

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
