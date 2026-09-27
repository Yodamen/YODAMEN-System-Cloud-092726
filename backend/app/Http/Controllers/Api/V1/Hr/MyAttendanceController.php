<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Hr\AttendancePhotoRequest;
use App\Services\Hr\AttendanceService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MyAttendanceController extends Controller
{
    public function index(Request $request, AttendanceService $service): JsonResponse
    {
        try {
            return response()->json($service->myAttendance($request->user(), [
                'from' => $request->query('from'),
                'to' => $request->query('to'),
            ]));
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function calendar(Request $request, AttendanceService $service): JsonResponse
    {
        try {
            return response()->json($service->myCalendar($request->user(), $request->query('month')));
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function timeIn(AttendancePhotoRequest $request, AttendanceService $service): JsonResponse
    {
        try {
            $attendance = $service->timeIn($request->user(), $request->file('proof'));
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Time In recorded using server time.',
            'attendance' => $service->serialize($attendance),
        ], 201);
    }

    public function timeOut(AttendancePhotoRequest $request, AttendanceService $service): JsonResponse
    {
        try {
            $attendance = $service->timeOut($request->user(), $request->file('proof'));
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Time Out recorded and sent for approval.',
            'attendance' => $service->serialize($attendance),
        ]);
    }
}
