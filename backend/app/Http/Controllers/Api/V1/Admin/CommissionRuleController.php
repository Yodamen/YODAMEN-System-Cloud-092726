<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\SaveCommissionRuleRequest;
use App\Services\Commissions\CommissionRuleService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommissionRuleController extends Controller
{
    public function index(Request $request, CommissionRuleService $service): JsonResponse
    {
        $businessId = $request->user()->business_id;

        return response()->json([
            'rules' => $service->list($businessId),
            'options' => $service->formOptions($businessId),
            'phase' => [
                'posting_active' => true,
                'basis' => 'Service/rendered session value or net retail sale value',
                'split_commission_supported' => false,
            ],
        ]);
    }

    public function store(SaveCommissionRuleRequest $request, CommissionRuleService $service): JsonResponse
    {
        try {
            $rule = $service->create($request->user(), $request->validated());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Commission rule created.',
            'rule' => $service->serialize($rule),
        ], 201);
    }

    public function update(
        SaveCommissionRuleRequest $request,
        string $commissionRuleId,
        CommissionRuleService $service,
    ): JsonResponse {
        try {
            $rule = $service->updateById(
                $request->user(),
                (int) $commissionRuleId,
                $request->validated(),
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Commission rule updated.',
            'rule' => $service->serialize($rule),
        ]);
    }

    public function toggle(Request $request, string $commissionRuleId, CommissionRuleService $service): JsonResponse
    {
        try {
            $rule = $service->toggleById($request->user(), (int) $commissionRuleId);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => $rule->status === 'ACTIVE' ? 'Commission rule activated.' : 'Commission rule deactivated.',
            'rule' => $service->serialize($rule),
        ]);
    }
}
