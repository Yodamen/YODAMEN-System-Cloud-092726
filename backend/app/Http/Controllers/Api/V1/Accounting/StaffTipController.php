<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Models\StaffTip;
use App\Services\Accounting\StaffTipService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffTipController extends Controller
{
    public function __construct(private readonly StaffTipService $service) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', 'in:PENDING,PAID'],
        ]);

        return response()->json($this->service->list($request->user(), $filters));
    }

    public function payout(Request $request, StaffTip $staffTip): JsonResponse
    {
        $data = $request->validate([
            'payout_account_id' => ['required', 'integer', 'exists:chart_of_accounts,id'],
            'payout_date' => ['required', 'date_format:Y-m-d'],
            'payout_reference' => ['nullable', 'string', 'max:180'],
        ]);

        try {
            return response()->json(['tip' => $this->service->payout($request->user(), $staffTip, $data)]);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
