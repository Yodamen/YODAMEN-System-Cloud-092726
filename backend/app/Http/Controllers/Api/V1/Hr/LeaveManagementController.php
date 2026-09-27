<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Hr\ReviewLeaveRequest;
use App\Http\Requests\Api\V1\Hr\SaveLeaveBalanceRequest;
use App\Models\HrLeaveRequest;
use App\Models\LeaveType;
use App\Models\Personnel;
use App\Services\Hr\LeaveService;
use App\Services\MasterData\WorkflowReasonCatalog;
use App\Services\MasterData\WorkflowReasonService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveManagementController extends Controller
{
    public function index(Request $request, LeaveService $service): JsonResponse
    {
        return response()->json($service->adminData($request->user(), [
            'status' => $request->query('status'),
            'personnel_id' => $request->query('personnel_id'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
        ]));
    }

    public function balances(Request $request, Personnel $personnel, LeaveService $service): JsonResponse
    {
        $year = (int) ($request->query('year') ?: now()->year);

        return response()->json($service->adminBalances($request->user(), $personnel, $year));
    }

    public function approve(
        ReviewLeaveRequest $request,
        HrLeaveRequest $leaveRequest,
        LeaveService $service,
    ): JsonResponse {
        try {
            $record = $service->approve($request->user(), $leaveRequest, $request->validated('notes'));
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Request approved.',
            'request' => $service->serialize($record),
        ]);
    }

    public function reject(
        ReviewLeaveRequest $request,
        HrLeaveRequest $leaveRequest,
        LeaveService $service,
        WorkflowReasonService $reasons,
    ): JsonResponse {
        $validated = $request->validate(['review_reason_reference_id' => ['required','integer'], 'notes' => ['required','string','min:3','max:2000']]);
        $reviewReason = $reasons->resolve($request->user(), (int) $validated['review_reason_reference_id'], WorkflowReasonCatalog::APPROVAL_REJECTION);
        try {
            $record = $service->reject($request->user(), $leaveRequest, $validated['notes'], (int) $reviewReason->id);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Request rejected.',
            'request' => $service->serialize($record),
        ]);
    }

    public function saveBalance(
        SaveLeaveBalanceRequest $request,
        Personnel $personnel,
        LeaveType $leaveType,
        int $year,
        LeaveService $service,
    ): JsonResponse {
        try {
            $balance = $service->saveBalance(
                $request->user(),
                $personnel,
                $leaveType,
                $year,
                $request->validated(),
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Leave balance updated.',
            'balance' => $balance,
        ]);
    }
}
