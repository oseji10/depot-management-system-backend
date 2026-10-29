<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use App\Modules\Organization\Models\Branch;
use App\Support\Auditing\Auditable;
use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/** Once posted, an entry is permanent. Mistakes are fixed by a reversal, never by editing. */
class JournalEntry extends Model
{
    use Auditable, BelongsToStore;

    protected $fillable = [
        'store_id', 'branch_id', 'number', 'status', 'entry_date', 'memo', 'total', 'source_type', 'source_id',
        'posting_key', 'reversal_of_id', 'reversed_at', 'created_by', 'posted_by', 'posted_at',
    ];

    protected function casts(): array
    {
        return ['entry_date' => 'date:Y-m-d', 'posted_at' => 'datetime', 'reversed_at' => 'datetime', 'total' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $entry) {
            if ($entry->getOriginal('status') !== 'posted') {
                return;
            }

            $changed = array_diff(array_keys($entry->getDirty()), ['reversed_at', 'updated_at']);

            if ($changed !== []) {
                throw new LogicException('A posted journal entry cannot be changed. Reverse it instead.');
            }
        });

        static::deleting(function (self $entry) {
            if ($entry->status === 'posted') {
                throw new LogicException('A posted journal entry cannot be deleted. Reverse it instead.');
            }
        });
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
