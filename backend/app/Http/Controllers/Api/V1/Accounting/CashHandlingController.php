<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Services\Accounting\CashHandlingService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashHandlingController extends Controller
{
    public function __construct(private readonly CashHandlingService $service) {}

    public function calendar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month' => ['required','date_format:Y-m'],
            'branch_id' => ['nullable','integer'],
        ]);
        try {
            return response()->json($this->service->calendar($request->user(), $data['month'], isset($data['branch_id']) ? (int) $data['branch_id'] : null));
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
