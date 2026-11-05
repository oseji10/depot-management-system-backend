<?php

namespace App\Modules\Containers\Models;

use App\Support\Auditing\Auditable;
use App\Support\Tenancy\BelongsToStore;
use App\Support\Concerns\SoftDeletesFreeingKeys;
use Illuminate\Database\Eloquent\Model;

class ContainerType extends Model
{
    use Auditable, BelongsToStore, SoftDeletesFreeingKeys;

    /** Unique values released when the record is deleted, with their column lengths. */
    protected array $freedOnDelete = ['name' => 80];

    public const KINDS = ['bottle', 'crate'];

    protected $fillable = ['store_id', 'name', 'kind', 'deposit', 'is_active'];

    protected function casts(): array
    {
        return ['deposit' => 'integer', 'is_active' => 'boolean'];
    }
}
