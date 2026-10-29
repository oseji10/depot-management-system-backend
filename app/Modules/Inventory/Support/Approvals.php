<?php

namespace App\Modules\Inventory\Support;

use App\Models\User;

class Approvals
{
    /** Maker-checker: nobody approves their own request, except owners (accountable for the whole depot). */
    public static function assertCanDecide(User $actor, int $creatorId): void
    {
        $self = $actor->id === $creatorId;

        abort_if($self && ! $actor->is_super_admin && ! $actor->hasRole('Owner'), 403,
            'You cannot approve your own request. Ask another approver.');
    }
}
