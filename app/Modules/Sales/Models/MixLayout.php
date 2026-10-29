<?php

namespace App\Modules\Sales\Models;

use App\Support\Auditing\Auditable;
use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/** One crate split into rows, each row its own product. Set by an administrator, never hard-coded. */
class MixLayout extends Model
{
    use Auditable, BelongsToStore;

    protected $fillable = ['store_id', 'name', 'row_count', 'row_size', 'is_active'];

    protected $appends = ['units'];

    protected function casts(): array
    {
        return ['row_count' => 'integer', 'row_size' => 'integer', 'is_active' => 'boolean'];
    }

    /** Bottles in one whole mixed crate. */
    public function getUnitsAttribute(): int
    {
        return $this->row_count * $this->row_size;
    }
}
