<?php

namespace App\Support\Tenancy;

use Illuminate\Support\Facades\Auth;

/**
 * Single source of truth for "what is this user allowed to see?".
 *
 *  platform : super admin. Sees every store. May narrow the view with X-Store-Id / X-Branch-Id headers.
 *  store    : store_id set, branch_id null. Sees every branch of their own store.
 *  branch   : store_id and branch_id set. Sees their own branch only.
 *
 * The headers are ignored for everyone except super admins.
 */
class AccessScope
{
    public function check(): bool
    {
        return Auth::check();
    }

    public function isSuperAdmin(): bool
    {
        return (bool) Auth::user()?->is_super_admin;
    }

    public function level(): string
    {
        $user = Auth::user();

        if (! $user) {
            return 'none';
        }

        if ($user->is_super_admin) {
            return 'platform';
        }

        return $user->branch_id ? 'branch' : 'store';
    }

    public function storeId(): ?int
    {
        $user = Auth::user();

        if (! $user) {
            return null;
        }

        return $user->is_super_admin ? $this->headerInt('X-Store-Id') : $user->store_id;
    }

    public function branchId(): ?int
    {
        $user = Auth::user();

        if (! $user) {
            return null;
        }

        return $user->is_super_admin ? $this->headerInt('X-Branch-Id') : $user->branch_id;
    }

    /** True when the actor can only work inside one branch. */
    public function isBranchRestricted(): bool
    {
        return $this->branchId() !== null;
    }

    /** For write operations: a store context must exist (super admins must pick a store first). */
    public function requireStoreId(): int
    {
        $id = $this->storeId();

        abort_if($id === null, 422, 'Select a store first.');

        return $id;
    }

    private function headerInt(string $name): ?int
    {
        $value = request()->header($name);

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }
}
