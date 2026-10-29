<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** Restricts queries to the actor's store. $column is 'id' when applied to the stores table itself. */
class StoreScope implements Scope
{
    public function __construct(private string $column = 'store_id')
    {
    }

    public function apply(Builder $builder, Model $model): void
    {
        $access = app(AccessScope::class);

        // Console, queue and public (login / register) code runs unscoped and must scope explicitly.
        if (! $access->check()) {
            return;
        }

        $storeId = $access->storeId();

        if ($storeId !== null) {
            $builder->where($model->qualifyColumn($this->column), $storeId);

            return;
        }

        // Super admin with no store selected sees everything; anyone else with no store sees nothing.
        if (! $access->isSuperAdmin()) {
            $builder->whereRaw('1 = 0');
        }
    }
}
