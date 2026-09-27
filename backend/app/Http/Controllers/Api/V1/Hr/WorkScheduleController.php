<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Hr\SaveWorkScheduleRequest;
use App\Models\Personnel;
use App\Services\Hr\WorkScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkScheduleController extends Controller
{
    public function show(Request $request, Personnel $personnel, WorkScheduleService $service): JsonResponse
    {
        return response()->json($service->get($request->user(), $personnel));
    }

    public function update(SaveWorkScheduleRequest $request, Personnel $personnel, WorkScheduleService $service): JsonResponse
    {
        return response()->json([
            'message' => 'Work schedule updated.',
            'schedule' => $service->save($request->user(), $personnel, $request->validated()),
        ]);
    }
}
