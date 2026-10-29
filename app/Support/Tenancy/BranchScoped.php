<?php

namespace App\Support\Tenancy;

use App\Modules\Organization\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Add to models with a branch_id that branch-level users must be confined to (sales, stock, shifts...). */
trait BranchScoped
{
    public static function bootBranchScoped(): void
    {
        static::addGlobalScope(new BranchScope());

        static::creating(function (Model $model) {
            $access = app(AccessScope::class);
            $branchId = $access->branchId();

            if ($branchId === null) {
                return;
            }

            if ($model->branch_id === null) {
                $model->branch_id = $branchId;
            }

            if ($access->check() && ! $access->isSuperAdmin() && (int) $model->branch_id !== $branchId) {
                throw new LogicException('Cross-branch write blocked.');
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
