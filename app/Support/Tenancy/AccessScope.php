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
 * X-Store-Id is ignored for everyone except super admins. A store-level user may send X-Branch-Id / X-Warehouse-Id to narrow the app to one of their own branches.
 */
class AccessScope
{
    private bool $unscoped = false;

    public function check(): bool
    {
        return ! $this->unscoped && Auth::check();
    }

    /** Runs code that sets up a brand-new business (it writes into a store the signed-in user is not in yet). */
    public function withoutScope(callable $fn): mixed
    {
        $this->unscoped = true;

        try {
            return $fn();
        } finally {
            $this->unscoped = false;
        }
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

        if ($user->is_super_admin) {
            return $this->headerInt('X-Branch-Id');
        }

        if ($user->branch_id) {
            return $user->branch_id;
        }

        // A store-level user (owner, manager) may narrow the whole app to one branch of their own business.
        return $this->validBranch($user->store_id, $this->headerInt('X-Branch-Id'));
    }

    /** @var array<string, bool> branch checks made during this request (this runs for every scoped query) */
    private array $branchChecks = [];

    private array $warehouseChecks = [];

    private function validBranch(?int $storeId, ?int $branchId): ?int
    {
        if (! $storeId || ! $branchId) {
            return null;
        }

        $this->branchChecks["{$storeId}:{$branchId}"] ??= \Illuminate\Support\Facades\DB::table('branches')->where('id', $branchId)->where('store_id', $storeId)->where('is_active', true)->exists();

        return $this->branchChecks["{$storeId}:{$branchId}"] ? $branchId : null;
    }

    /** The warehouse a store-level user chose to work in; only lists that can filter by warehouse use it. */
    public function warehouseId(): ?int
    {
        $user = Auth::user();
        $id = $user ? $this->headerInt('X-Warehouse-Id') : null;

        if (! $id || ! $user->store_id || $user->is_super_admin || $user->branch_id) {
            return null;
        }

        $this->warehouseChecks["{$user->store_id}:{$id}"] ??= \Illuminate\Support\Facades\DB::table('warehouses')->where('id', $id)->where('store_id', $user->store_id)->exists();

        return $this->warehouseChecks["{$user->store_id}:{$id}"] ? $id : null;
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
