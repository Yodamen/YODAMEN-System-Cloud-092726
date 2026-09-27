<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Controller;
use App\Models\ZReading;
use App\Services\Sales\ZReadingService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ZReadingController extends Controller
{
    public function preview(Request $request, ZReadingService $service): JsonResponse
    {
        $request->validate(['branch_id' => ['required', 'integer'], 'date' => ['required', 'date_format:Y-m-d']]);
        try {
            return response()->json($service->preview($request->user(), $request->integer('branch_id'), (string) $request->query('date')));
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function close(Request $request, ZReadingService $service): JsonResponse
    {
        $data = $request->validate(['branch_id' => ['required', 'integer'], 'business_date' => ['required', 'date_format:Y-m-d']]);
        try {
            $reading = $service->close($request->user(), (int) $data['branch_id'], $data['business_date']);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        return response()->json(['message' => 'Z-Reading closed.', 'z_reading' => $service->serialize($reading)]);
    }

    public function reopen(Request $request, ZReading $zReading, ZReadingService $service): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        try {
            $reading = $service->reopen($request->user(), $zReading, $data['reason']);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        return response()->json(['message' => 'Z-Reading reopened.', 'z_reading' => $service->serialize($reading)]);
    }
}
