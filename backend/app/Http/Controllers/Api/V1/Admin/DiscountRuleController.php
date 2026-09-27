<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\SaveDiscountRuleRequest;
use App\Services\Discounts\DiscountRuleService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DiscountRuleController extends Controller
{
    public function index(Request $request, DiscountRuleService $service): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;

        return response()->json([
            'rules' => $service->list($businessId),
            'options' => $service->formOptions($businessId),
            'phase' => [
                'checkout_active' => true,
                'stacking_supported' => true,
                'resolution' => 'Most specific valid rule, then Priority',
            ],
        ]);
    }

    public function store(SaveDiscountRuleRequest $request, DiscountRuleService $service): JsonResponse
    {
        try {
            $rule = $service->create($request->user(), $request->validated());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Promotion rule created.',
            'rule' => $service->serialize($rule),
        ], 201);
    }

    public function update(
        SaveDiscountRuleRequest $request,
        string $discountRuleId,
        DiscountRuleService $service,
    ): JsonResponse {
        try {
            $rule = $service->updateById($request->user(), (int) $discountRuleId, $request->validated());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Promotion rule updated.',
            'rule' => $service->serialize($rule),
        ]);
    }

    public function toggle(Request $request, string $discountRuleId, DiscountRuleService $service): JsonResponse
    {
        try {
            $rule = $service->toggleById($request->user(), (int) $discountRuleId);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => $rule->status === 'ACTIVE' ? 'Promotion rule activated.' : 'Promotion rule deactivated.',
            'rule' => $service->serialize($rule),
        ]);
    }
}
