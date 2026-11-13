<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Organization\Models\Branch;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class UserController extends Controller
{
    public function __construct(private AccessScope $access)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $users = User::query()->visibleToActor()
            ->with(AccessScope::hasUserBranches() ? ['branch:id,name', 'branches:id,name', 'store:id,name'] : ['branch:id,name', 'store:id,name'])
            ->when($request->query('branch_id'), fn ($q, $id) => $q->where(fn ($w) => $w->where('users.branch_id', $id)->when(AccessScope::hasUserBranches(), fn ($x) => $x->orWhereIn('users.id', DB::table('user_branches')->where('branch_id', $id)->select('user_id')))))
            ->when($request->query('search'), fn ($q, $s) => $q->where(
                fn ($w) => $w->where('users.name', 'like', "%{$s}%")->orWhere('users.email', 'like', "%{$s}%")
            ))
            ->orderBy('users.name')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        $this->attachRoles($users->getCollection());

        return response()->json($users);
    }

    public function show(int $id): JsonResponse
    {
        $user = $this->find($id)->load(AccessScope::hasUserBranches() ? ['branch:id,name', 'branches:id,name', 'store:id,name'] : ['branch:id,name', 'store:id,name']);
        $this->attachRoles(collect([$user]));

        return response()->json($user);
    }

    public function store(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', Password::min(8)],
            'role_id' => ['required', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'branch_ids' => ['sometimes', 'array', 'max:50'],
            'branch_ids.*' => ['integer'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $role = $this->roleInStore($data['role_id'], $storeId);
        $this->assertCanAssign($role);

        $user = DB::transaction(function () use ($data, $role, $storeId) {
            $branchIds = $this->resolveBranchIds($data);
            $user = User::create([
                ...collect($data)->except(['role_id', 'branch_id', 'branch_ids'])->all(),
                'store_id' => $storeId,
                'branch_id' => $branchIds[0] ?? null,
            ]);
            if (AccessScope::hasUserBranches()) {
                $user->branches()->sync($branchIds);
            }

            app(PermissionRegistrar::class)->setPermissionsTeamId($storeId);
            $user->assignRole($role);

            return $user;
        });

        app(\App\Modules\Organization\Services\NotificationEmailer::class)->accountCreated($user, $storeId, $role->name);

        return response()->json($user, 201);
    }

    /** Email the person a link to choose a new password. Sent immediately so a failure is reported, not hidden. */
    public function sendReset(int $id): JsonResponse
    {
        $user = $this->find($id);
        $this->assertCanManage($user);

        abort_unless($user->is_active, 422, 'This user is disabled. Enable them first.');
        abort_unless($user->email, 422, 'This user has no email address.');

        try {
            $token = \Illuminate\Support\Facades\Password::broker()->createToken($user);
            $url = config('depot.frontend_url').'/reset-password/?token='.$token.'&email='.urlencode($user->email);
            \Illuminate\Support\Facades\Mail::to($user->email)->send(new \App\Mail\PasswordResetMail($user->name, $url, (int) config('auth.passwords.users.expire', 60)));
        } catch (\Throwable $e) {
            report($e);
            abort(422, 'The email could not be sent: '.$e->getMessage());
        }

        return response()->json(['message' => 'A password reset link was sent to '.$user->email.'.']);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $this->find($id);
        $this->assertCanManage($user);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['nullable', Password::min(8)],
            'role_id' => ['sometimes', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'branch_ids' => ['sometimes', 'array', 'max:50'],
            'branch_ids.*' => ['integer'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $isSelf = $user->id === $request->user()->id;
        abort_if($isSelf && array_key_exists('is_active', $data) && ! $data['is_active'], 422, 'You cannot disable your own account.');
        if ($isSelf) {
            unset($data['role_id']); // nobody can change their own role
        }

        DB::transaction(function () use ($user, $data) {
            $fill = collect($data)->except(['role_id', 'branch_id', 'branch_ids', 'password'])->all();

            if (! empty($data['password'])) {
                $fill['password'] = $data['password'];
            }

            // Only head-office actors move a user between branches. The first branch is their main one; none = every branch.
            $branchIds = null;
            if ((array_key_exists('branch_ids', $data) || array_key_exists('branch_id', $data)) && ! $this->access->isBranchLocked()) {
                $branchIds = $this->resolveBranchIds($data);
                $fill['branch_id'] = $branchIds[0] ?? null;
            }

            $user->update($fill);

            if ($branchIds !== null) {
                if (AccessScope::hasUserBranches()) {
                    $user->branches()->sync($branchIds);
                }
                DB::table('user_stores')->where('user_id', $user->id)->where('store_id', $user->store_id)->update(['branch_id' => $branchIds[0] ?? null]);
            }

            if (isset($data['role_id'])) {
                $role = $this->roleInStore($data['role_id'], $user->store_id);
                $this->assertCanAssign($role);

                app(PermissionRegistrar::class)->setPermissionsTeamId($user->store_id);
                $user->syncRoles([$role]);
            }

            // A disabled user must be signed out everywhere immediately.
            if (array_key_exists('is_active', $data) && ! $data['is_active']) {
                $user->tokens()->delete();
            }
        });

        return response()->json($user->fresh());
    }

    /** Always resolve users through the actor's visibility; users have no global scope. */
    private function find(int $id): User
    {
        return User::query()->visibleToActor()->findOrFail($id);
    }

    /**
     * The branches a user works in, main branch first. Empty means the whole business (head office).
     * A branch-bound actor can only hand out branches they work in themselves, and never "the whole business".
     *
     * @return list<int>
     */
    private function resolveBranchIds(array $data): array
    {
        $ids = array_key_exists('branch_ids', $data)
            ? array_values(array_unique(array_map('intval', $data['branch_ids'] ?? [])))
            : (! empty($data['branch_id']) ? [(int) $data['branch_id']] : []);

        $ids = array_map(fn ($id) => Branch::findOrFail($id)->id, $ids);

        if ($this->access->isBranchLocked()) {
            $mine = $this->access->allowedBranches(request()->user());
            $ids = array_values(array_intersect($ids, $mine));

            return $ids ?: [$this->access->branchId()];
        }

        return $ids;
    }

    /** A branch-level actor always creates users in their own branch; otherwise the branch must be visible. */
    private function resolveBranchId(?int $requested): ?int
    {
        if ($this->access->branchId() !== null) {
            return $this->access->branchId();
        }

        return $requested ? Branch::findOrFail($requested)->id : null;
    }

    private function roleInStore(int $roleId, int $storeId): Role
    {
        return Role::where('store_id', $storeId)->findOrFail($roleId);
    }

    /** Nobody can hand out permissions they do not hold themselves, and only owners create owners. */
    private function assertCanAssign(Role $role): void
    {
        $actor = request()->user();

        if ($actor->is_super_admin) {
            return;
        }

        abort_if(($role->system_key ?? ($role->name === 'Owner' ? 'owner' : null)) === 'owner' && ! $actor->isOwner(), 403, 'Only an owner can assign the Owner role.');

        $missing = $role->permissions->pluck('name')->diff($actor->getAllPermissions()->pluck('name'));
        abort_if($missing->isNotEmpty(), 403, 'You cannot assign a role with permissions you do not have.');
    }

    private function assertCanManage(User $target): void
    {
        $actor = request()->user();

        if ($actor->is_super_admin) {
            return;
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($target->store_id);
        abort_if($target->isOwner() && ! $actor->isOwner(), 403, 'Only an owner can change an owner.');
    }

    /** Spatie's roles relation is team-filtered per request, so read names straight from the pivot for lists. */
    private function attachRoles($users): void
    {
        $rows = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->whereIn('model_has_roles.model_id', $users->pluck('id'))
            ->get(['model_has_roles.model_id', 'roles.id', 'roles.name'])
            ->keyBy('model_id');

        $users->each(fn ($u) => $u->setAttribute(
            'role', $rows->has($u->id) ? ['id' => $rows[$u->id]->id, 'name' => $rows[$u->id]->name] : null
        ));
    }
}
