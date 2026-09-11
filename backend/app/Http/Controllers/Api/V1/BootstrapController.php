<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\Foundation\BusinessClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BootstrapController extends Controller
{
    public function __invoke(Request $request, BusinessClock $clock): JsonResponse
    {
        $user = $request->user()->load(['role.permissions', 'business', 'defaultBranch']);

        $branches = $user->business_id
            ? Branch::query()
                ->where('business_id', $user->business_id)
                ->where('status', 'ACTIVE')
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'short_code', 'timezone'])
            : collect();

        return response()->json([
            'system' => [
                'name' => config('app.name'),
                'timezone' => $clock->timezone(),
                'business_date' => $clock->today(),
                'business_time' => $clock->now()->toIso8601String(),
            ],
            'business' => $user->business ? [
                'code' => $user->business->code,
                'name' => $user->business->name,
                'legal_name' => $user->business->legal_name,
                'timezone' => $user->business->timezone,
                'currency' => $user->business->currency,
            ] : null,
            'branches' => $branches,
            'permissions' => $user->permissionCodes(),
        ]);
    }
}
