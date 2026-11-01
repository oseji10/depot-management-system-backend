<?php

namespace App\Http\Middleware;

use App\Modules\Organization\Services\SubscriptionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Read-only once a subscription has run out, blocked a little later. The billing screen and sign-out always work. */
class EnforceSubscription
{
    public function __construct(private SubscriptionService $subscriptions)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->is_super_admin || ! $user->store_id) {
            return $next($request);
        }

        $path = trim(preg_replace('#^api/v1/#', '', $request->path()), '/');

        if (str_starts_with($path, 'auth/') || $path === 'subscription' || $path === 'notifications/email' || str_starts_with($path, 'subscription/')
            || ($path === 'businesses' && $request->isMethod('GET')) || preg_match('#^businesses/\d+/switch$#', $path)) {
            return $next($request);
        }

        $mode = $this->subscriptions->state($user->store_id)['mode'];

        if ($mode === 'blocked') {
            return response()->json(['message' => 'Your subscription has ended. Renew it to carry on.', 'code' => 'subscription_blocked'], 402);
        }

        if ($mode === 'readonly' && ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return response()->json(['message' => 'Your subscription has ended, so the system is read-only. Renew it to make changes.', 'code' => 'subscription_readonly'], 402);
        }

        return $next($request);
    }
}
