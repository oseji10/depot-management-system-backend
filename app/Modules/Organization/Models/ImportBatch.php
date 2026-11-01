<?php

namespace App\Modules\Organization\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One uploaded file: who brought it in, when, and what it did. */
class ImportBatch extends Model
{
    use BelongsToStore;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['counts' => 'array', 'undone_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
