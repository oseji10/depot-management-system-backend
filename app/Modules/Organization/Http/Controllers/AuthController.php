<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Organization\Services\StoreProvisioner;
use App\Support\Auditing\AuditLogger;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

class AuthController extends Controller
{
    /** Self-service onboarding: creates the store, head office, main warehouse, roles and the owner. */
    public function registerStore(Request $request, StoreProvisioner $provisioner): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'branch_name' => ['nullable', 'string', 'max:100'],
            'owner_name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'owner_password' => ['required', Password::min(8)],
        ]);

        [, $owner] = $provisioner->provision($data);

        AuditLogger::record('store_registered', $owner->store, actor: $owner);

        return response()->json($this->session($owner), 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            AuditLogger::record('login_failed', null, null, ['email' => $data['email']], null, $user?->store_id);

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        abort_unless($user->is_active, 403, 'Your account is disabled.');

        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        AuditLogger::record('login', $user, actor: $user);

        return response()->json($this->session($user, $data['device_name'] ?? 'web'));
    }

    public function logout(Request $request): JsonResponse
    {
        AuditLogger::record('logout', $request->user());
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->payload($request->user()));
    }

    private function session(User $user, string $device = 'web'): array
    {
        return [
            'token' => $user->createToken($device, ['*'], now()->addDays(7))->plainTextToken,
            ...$this->payload($user),
        ];
    }

    private function payload(User $user): array
    {
        // Roles live inside a store, so point Spatie at the user's store before reading them.
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->store_id);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        return [
            'user' => $user->only(['id', 'name', 'email', 'phone', 'is_super_admin', 'store_id', 'branch_id']),
            'store' => $user->store ? [...$user->store->only(['id', 'name', 'slug', 'currency', 'timezone', 'logo_url', 'address', 'phone']), 'disabled_menus' => $user->store->settings['disabled_menus'] ?? []] : null,
            'branch' => $user->branch?->only(['id', 'name', 'code']),
            'level' => $user->is_super_admin ? 'platform' : ($user->branch_id ? 'branch' : 'store'),
            'van' => ($van = \App\Modules\Organization\Models\Warehouse::vanOf($user->id)) ? ['id' => $van->id, 'name' => $van->name] : null,
            'roles' => $user->getRoleNames()->values(),
            'permissions' => $user->is_super_admin ? ['*'] : ($user->hasRole('Owner') ? \App\Support\Permissions::all() : $user->getAllPermissions()->pluck('name')->values()),
        ];
    }
}
