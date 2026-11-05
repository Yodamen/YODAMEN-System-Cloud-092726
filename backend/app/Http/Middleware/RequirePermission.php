<?php

namespace App\Http\Middleware;

use App\Services\Security\AuditService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    public function handle(Request $request, Closure $next, string $module, string $action): Response
    {
        $user = $request->user();

        if (! $user) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }

        if (! $user->hasPermission($module, $action)) {
            if (! app(\App\Services\Subscriptions\SubscriptionService::class)->allowsModule($user->business, $module)) {
                return new JsonResponse([
                    'message' => 'This feature is not included in your subscription.',
                    'code' => 'SUBSCRIPTION_UPGRADE_REQUIRED',
                    'module' => $module,
                ], 403);
            }

            $this->audit->record(
                action: 'ACCESS_DENIED',
                module: strtolower(trim($module)),
                entityType: null,
                entityId: null,
                reason: 'Action: '.strtoupper(trim($action)),
                actor: $user,
            );

            return new JsonResponse([
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        return $next($request);
    }
}
