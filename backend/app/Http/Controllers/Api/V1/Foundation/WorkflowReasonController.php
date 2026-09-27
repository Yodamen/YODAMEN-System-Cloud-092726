<?php

namespace App\Http\Controllers\Api\V1\Foundation;

use App\Http\Controllers\Controller;
use App\Services\MasterData\WorkflowReasonCatalog;
use App\Services\MasterData\WorkflowReasonService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkflowReasonController extends Controller
{
    public function index(Request $request, WorkflowReasonService $service): JsonResponse
    {
        $context = strtoupper(trim((string) $request->query('context', '')));
        try {
            $reasons = $service->options($request->user(), $context);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['context' => $context, 'reasons' => $reasons]);
    }

    public function contexts(): JsonResponse
    {
        return response()->json([
            'contexts' => collect(WorkflowReasonCatalog::contexts())
                ->map(fn (string $name, string $code) => ['code' => $code, 'name' => $name])
                ->values(),
        ]);
    }
}
