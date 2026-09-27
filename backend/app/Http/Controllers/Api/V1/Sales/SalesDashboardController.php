<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Controller;
use App\Services\Sales\SalesReportingService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesDashboardController extends Controller
{
    public function __invoke(Request $request, SalesReportingService $service): JsonResponse
    {
        try {
            return response()->json($service->dashboard($request->user(), $request->only(['from', 'to', 'branch_id', 'compare_enabled', 'compare_from', 'compare_to', 'top_n'])));
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
