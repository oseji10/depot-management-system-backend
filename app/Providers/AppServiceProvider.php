<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Super admins pass every permission check.
        Gate::before(function (User $user, string $ability) {
            if ($user->is_super_admin) {
                return true;
            }

            // A store's Owner can do everything inside their own store, including features added after the store was set up.
            if ($ability !== 'platform' && $user->store_id) {
                try {
                    app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($user->store_id);

                    return $user->hasRole('Owner') ? true : null;
                } catch (\Throwable) {
                    return null;
                }
            }

            return null;
        });

        // Used by `can:platform` on routes that only super admins may reach.
        Gate::define('platform', fn (User $user) => $user->is_super_admin);
    }
}
