<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Hr\SaveLeaveRequest;
use App\Models\HrLeaveRequest;
use App\Services\Hr\LeaveService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MyLeaveController extends Controller
{
    public function index(Request $request, LeaveService $service): JsonResponse
    {
        return response()->json($service->myData($request->user()));
    }

    public function store(SaveLeaveRequest $request, LeaveService $service): JsonResponse
    {
        try {
            $record = $service->submit($request->user(), $request->validated());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Request submitted.',
            'request' => $service->serialize($record),
        ], 201);
    }

    public function cancel(Request $request, HrLeaveRequest $leaveRequest, LeaveService $service): JsonResponse
    {
        try {
            $record = $service->cancel($request->user(), $leaveRequest);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Request cancelled.',
            'request' => $service->serialize($record),
        ]);
    }
}
