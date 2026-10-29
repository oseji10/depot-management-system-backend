<?php

namespace App\Modules\Accounting\Http;

use App\Support\Tenancy\AccessScope;

/**
 * The books belong to the whole depot. A branch-level user could only see part of an entry, which would make
 * every total wrong, so the accounting screens are for store-level users and the platform admin.
 */
trait StoreLevelOnly
{
    protected function guardStoreLevel(): int
    {
        $access = app(AccessScope::class);

        abort_if($access->isBranchRestricted() && ! $access->isSuperAdmin(), 403, 'Accounting is only available to store-level users.');

        return $access->requireStoreId();
    }
}
