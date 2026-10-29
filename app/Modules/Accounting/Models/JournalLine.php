<?php

namespace App\Modules\Accounting\Models;

use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class JournalLine extends Model
{
    use BelongsToStore;

    public $timestamps = false;

    protected $fillable = ['store_id', 'journal_entry_id', 'account_id', 'branch_id', 'debit', 'credit', 'memo'];

    protected function casts(): array
    {
        return ['debit' => 'integer', 'credit' => 'integer'];
    }

    protected static function booted(): void
    {
        $guard = function (self $line) {
            $status = JournalEntry::withoutGlobalScopes()->whereKey($line->journal_entry_id)->value('status');

            if ($status === 'posted') {
                throw new LogicException('Lines of a posted journal entry cannot be changed.');
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
