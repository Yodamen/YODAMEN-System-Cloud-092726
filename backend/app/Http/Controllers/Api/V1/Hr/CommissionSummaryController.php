<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Http\Controllers\Controller;
use App\Services\Commissions\CommissionEarningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommissionSummaryController extends Controller
{
    public function index(Request $request, CommissionEarningService $service): JsonResponse
    {
        return response()->json($service->list($request->user(), [
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'personnel_id' => $request->query('personnel_id'),
            'status' => $request->query('status'),
            'booking_code' => $request->query('booking_code'),
            'rule_id' => $request->query('rule_id'),
        ]));
    }
}
