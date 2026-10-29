<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/** Runs after auth:sanctum. Blocks disabled accounts/stores/branches and sets the permission team. */
class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            return response()->json(['message' => 'Your account is disabled.'], 403);
        }

        if (! $user->is_super_admin) {
            if (! $user->store?->is_active) {
                return response()->json(['message' => 'This store is suspended. Contact support.'], 403);
            }

            if ($user->branch_id && ! $user->branch?->is_active) {
                return response()->json(['message' => 'Your branch is disabled.'], 403);
            }
        }

        // Roles are per store, so Spatie must resolve them inside the user's store.
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->store_id);

        return $next($request);
    }
}
