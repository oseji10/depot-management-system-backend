<?php

namespace App\Modules\Organization\Models;

use App\Support\Auditing\Auditable;
use App\Support\Tenancy\BelongsToStore;
use App\Support\Tenancy\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Warehouse extends Model
{
    use Auditable, BelongsToStore, BranchScoped, SoftDeletes;

    protected $fillable = ['store_id', 'branch_id', 'name', 'code', 'type', 'assigned_user_id', 'is_default', 'is_active'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    /** The van that belongs to this user, if they are a salesperson. Not scoped: used while checking who may sell. */
    public static function vanOf(int $userId): ?self
    {
        return static::withoutGlobalScopes()->where('type', 'van')->where('assigned_user_id', $userId)->first();
    }

    /** A salesperson's van: stock held in their custody, sold only by them. */
    public function isVan(): bool
    {
        return $this->type === 'van';
    }
}
