<?php

namespace App\Http\Controllers\Api\V1\Packages;

use App\Http\Controllers\Controller;
use App\Models\PackageEnrollment;
use App\Services\Packages\ServiceBalanceReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceBalanceController extends Controller
{
    public function __construct(private readonly ServiceBalanceReadService $balances)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'balances' => $this->balances->list(
                $request->user(),
                $request->query('search'),
                $request->query('status'),
            ),
            'statuses' => [
                ['code' => 'ALL', 'label' => 'All'],
                ['code' => 'PENDING_CHECKOUT', 'label' => 'Pending Checkout'],
                ['code' => 'ACTIVE', 'label' => 'Active'],
                ['code' => 'COMPLETED', 'label' => 'Completed'],
            ],
        ]);
    }

    public function financials(Request $request, PackageEnrollment $enrollment): JsonResponse
    {
        return response()->json([
            'package' => $this->balances->detail($request->user(), $enrollment),
        ]);
    }
}
