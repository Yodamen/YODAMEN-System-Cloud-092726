<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Hr\AdjustAttendanceRequest;
use App\Models\Attendance;
use App\Services\Hr\AttendanceService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceController extends Controller
{
    public function index(Request $request, AttendanceService $service): JsonResponse
    {
        return response()->json($service->adminDashboard($request->user(), [
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'personnel_id' => $request->query('personnel_id'),
            'status' => $request->query('status'),
        ]));
    }

    public function approve(Request $request, Attendance $attendance, AttendanceService $service): JsonResponse
    {
        try {
            $attendance = $service->approve($request->user(), $attendance);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Attendance approved.',
            'attendance' => $service->serialize($attendance),
        ]);
    }

    public function adjust(
        AdjustAttendanceRequest $request,
        Attendance $attendance,
        AttendanceService $service,
    ): JsonResponse {
        try {
            $attendance = $service->adjust($request->user(), $attendance, $request->validated());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Attendance adjusted.',
            'attendance' => $service->serialize($attendance),
        ]);
    }

    public function proof(
        Request $request,
        Attendance $attendance,
        string $type,
        AttendanceService $service,
    ): StreamedResponse {
        $proof = $service->proofFor($request->user(), $attendance, $type);

        abort_unless(Storage::disk($proof->disk)->exists($proof->path), 404);

        $safeName = strtolower($proof->proof_type) === 'time_in'
            ? 'attendance-time-in'
            : 'attendance-time-out';
        $extension = match (strtolower($proof->mime_type)) {
            'image/png' => '.png',
            'image/webp' => '.webp',
            default => '.jpg',
        };

        return Storage::disk($proof->disk)->response(
            $proof->path,
            $safeName.$extension,
            [
                'Content-Type' => $proof->mime_type,
                'Cache-Control' => 'private, no-store, max-age=0',
            ],
            'inline',
        );
    }
}
