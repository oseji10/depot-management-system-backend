<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** Restricts queries to the actor's branch when they are branch-level. $column is 'id' on the branches table. */
class BranchScope implements Scope
{
    public function __construct(private string $column = 'branch_id')
    {
    }

    public function apply(Builder $builder, Model $model): void
    {
        $access = app(AccessScope::class);

        if (! $access->check()) {
            return;
        }

        $branchId = $access->branchId();

        if ($branchId !== null) {
            $builder->where($model->qualifyColumn($this->column), $branchId);
        }
    }
}
