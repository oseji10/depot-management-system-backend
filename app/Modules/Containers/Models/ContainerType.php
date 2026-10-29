<?php

namespace App\Modules\Containers\Models;

use App\Support\Auditing\Auditable;
use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class ContainerType extends Model
{
    use Auditable, BelongsToStore;

    public const KINDS = ['bottle', 'crate'];

    protected $fillable = ['store_id', 'name', 'kind', 'deposit', 'is_active'];

    protected function casts(): array
    {
        return ['deposit' => 'integer', 'is_active' => 'boolean'];
    }
}
