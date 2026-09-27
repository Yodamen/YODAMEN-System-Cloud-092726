<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Controller;
use App\Services\Sales\SalesReportingService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesDashboardTrendController extends Controller
{
    public function __invoke(Request $request, SalesReportingService $service): JsonResponse
    {
        try {
            return response()->json($service->trend($request->user(), $request->only(['type', 'year', 'branch_id'])));
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
