<?php

namespace App\Http\Middleware;

use App\Services\Subscriptions\SubscriptionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireSubscriptionState
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe() && ! $request->is('api/v1/auth/*', 'api/v1/subscription/activate')) {
            $business = $request->user()?->business;
            if (! $business || app(SubscriptionService::class)->snapshot($business)['is_read_only']) {
                return response()->json([
                    'message' => 'Your trial or license has ended. Activate a valid license to continue. You can still view existing records.',
                    'code' => 'SUBSCRIPTION_READ_ONLY',
                ], 403);
            }
        }

        return $next($request);
    }
}
