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

    /**
     * The van that belongs to this user, if they are a van salesman. Not scoped: used while checking who may sell.
     * A van only counts while the person still holds the Van Salesman role: someone who has moved to another job keeps no
     * van menu or van-only restrictions, even if the van was never handed back.
     */
    public static function vanOf(int $userId): ?self
    {
        return static::withoutGlobalScopes()->where('type', 'van')->where('assigned_user_id', $userId)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('model_has_roles as mr')
                ->join('roles as r', 'r.id', '=', 'mr.role_id')
                ->whereColumn('mr.model_id', 'warehouses.assigned_user_id')
                ->where('mr.model_type', 'like', '%User')
                ->where('r.system_key', 'van_salesman'))
            ->first();
    }

    /** A van salesman's van: stock held in their custody, sold only by them. */
    public function isVan(): bool
    {
        return $this->type === 'van';
    }
}
