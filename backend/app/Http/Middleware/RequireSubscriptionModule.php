<?php

namespace App\Http\Middleware;

use App\Services\Subscriptions\SubscriptionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireSubscriptionModule
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $subscriptions = app(SubscriptionService::class);
        $business = $request->user()?->business;
        if (! $subscriptions->allowsModule($business, $module)) {
            return response()->json([
                'message' => 'This feature is not included in your subscription.',
                'code' => 'SUBSCRIPTION_UPGRADE_REQUIRED',
                'module' => $module,
                'tier' => $subscriptions->snapshot($business)['tier'],
            ], 403);
        }

        return $next($request);
    }
}
