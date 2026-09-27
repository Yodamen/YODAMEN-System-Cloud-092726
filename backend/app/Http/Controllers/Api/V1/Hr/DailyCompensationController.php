<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\DailyCompensationService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DailyCompensationController extends Controller
{
    public function index(Request $request, DailyCompensationService $service): JsonResponse
    {
        return response()->json($service->list($request->user(), [
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'personnel_id' => $request->query('personnel_id'),
        ]));
    }

    public function rebuild(Request $request, DailyCompensationService $service): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
            'personnel_id' => ['nullable', 'integer'],
        ]);

        try {
            return response()->json($service->rebuildRange(
                $request->user(),
                $validated['from'],
                $validated['to'],
                isset($validated['personnel_id']) ? (int) $validated['personnel_id'] : null,
            ));
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
