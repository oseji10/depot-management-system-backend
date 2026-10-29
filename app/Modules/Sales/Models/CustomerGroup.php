<?php

namespace App\Modules\Sales\Models;

use App\Support\Auditing\Auditable;
use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class CustomerGroup extends Model
{
    use Auditable, BelongsToStore;

    public const KINDS = ['route' => 'Route', 'location' => 'Location', 'segment' => 'Segment', 'other' => 'Other'];

    protected $fillable = ['store_id', 'name', 'kind', 'notes', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
