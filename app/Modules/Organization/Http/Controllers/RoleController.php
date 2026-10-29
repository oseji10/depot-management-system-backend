<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Permissions;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function __construct(private AccessScope $access)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user->can('roles.view') || $user->can('users.create') || $user->can('users.update'),
            403
        );

        $storeId = $this->access->storeId();

        $roles = $storeId === null
            ? collect()
            : Role::where('store_id', $storeId)->with('permissions:id,name')->orderBy('name')->get()
                ->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'is_template' => array_key_exists($r->name, Permissions::roleTemplates()),
                    'permissions' => $r->permissions->pluck('name')->values(),
                ]);

        return response()->json(['data' => $roles->values()]);
    }

    public function permissions(): JsonResponse
    {
        return response()->json(['data' => Permissions::CATALOGUE]);
    }

    public function store(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('roles', 'name')->where('store_id', $storeId)],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', Rule::in(Permissions::all())],
        ]);

        $this->assertCanGrant($data['permissions']);

        $role = DB::transaction(function () use ($data, $storeId) {
            $role = Role::create(['name' => $data['name'], 'guard_name' => 'web', 'store_id' => $storeId]);
            $role->syncPermissions($data['permissions']);

            return $role;
        });

        return response()->json($role, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $role = Role::where('store_id', $this->access->requireStoreId())->findOrFail($id);
        abort_if($role->name === 'Owner', 422, 'The Owner role cannot be edited.');

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:60',
                Rule::unique('roles', 'name')->where('store_id', $role->store_id)->ignore($role->id)],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', Rule::in(Permissions::all())],
        ]);

        $this->assertCanGrant($data['permissions']);

        DB::transaction(function () use ($role, $data) {
            if (isset($data['name']) && ! array_key_exists($role->name, Permissions::roleTemplates())) {
                $role->update(['name' => $data['name']]);
            }
            $role->syncPermissions($data['permissions']);
        });

        return response()->json($role);
    }

    public function destroy(int $id): JsonResponse
    {
        $role = Role::where('store_id', $this->access->requireStoreId())->findOrFail($id);

        abort_if(array_key_exists($role->name, Permissions::roleTemplates()), 422, 'Standard roles cannot be deleted.');
        abort_if(DB::table('model_has_roles')->where('role_id', $role->id)->exists(), 422,
            'Reassign the users in this role first.');

        $role->delete();

        return response()->json(null, 204);
    }

    /** You can only put permissions into a role that you hold yourself. */
    private function assertCanGrant(array $permissions): void
    {
        $actor = request()->user();

        if ($actor->is_super_admin) {
            return;
        }

        $missing = collect($permissions)->diff($actor->getAllPermissions()->pluck('name'));
        abort_if($missing->isNotEmpty(), 403, 'You cannot grant permissions you do not have.');
    }
}
