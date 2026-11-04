<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\Subscriptions\SubscriptionService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function show(Request $request, SubscriptionService $subscriptions): JsonResponse
    {
        $this->authorizeAdministrator($request);

        return response()->json($this->details($request->user()->business, $subscriptions));
    }

    public function activate(Request $request, SubscriptionService $subscriptions): JsonResponse
    {
        $this->authorizeAdministrator($request);
        $data = $request->validate(['activation_code' => ['required', 'string', 'max:4096']]);
        try {
            $business = $subscriptions->activate($request->user(), trim($data['activation_code']));
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'Subscription activated.', ...$this->details($business, $subscriptions)]);
    }

    private function authorizeAdministrator(Request $request): void
    {
        $user = $request->user();
        abort_unless($user && $user->isActive() && $user->isSuperAdmin() && $user->role?->active && $user->business !== null, 403);
    }

    private function details(Business $business, SubscriptionService $subscriptions): array
    {
        return [
            'subscription' => $subscriptions->snapshot($business),
            'installation_id' => $business->license_installation_id,
            'business_code' => $business->code,
            'revision' => (int) $business->license_revision,
        ];
    }
}
