<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Sales\FinalizeRetailPosSaleRequest;
use App\Http\Requests\Api\V1\Sales\PreviewRetailPosSaleRequest;
use App\Services\Foundation\IdempotencyService;
use App\Services\Foundation\SupportingDocumentService;
use App\Services\Sales\RetailPosService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class RetailPosController extends Controller
{
    public function __construct(
        private readonly RetailPosService $service,
        private readonly IdempotencyService $idempotency,
        private readonly SupportingDocumentService $documents,
    ) {
    }

    public function options(Request $request): JsonResponse
    {
        try {
            return response()->json($this->service->options($request->user(), $request->integer('branch_id') ?: null));
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'sales' => $this->service->history($request->user(), $request->only(['search', 'status', 'branch_id', 'from', 'to', 'customer_id', 'personnel_id'])),
        ]);
    }

    public function preview(PreviewRetailPosSaleRequest $request): JsonResponse
    {
        try {
            return response()->json([
                'preview' => $this->service->preview($request->user(), $request->validated()),
            ]);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function store(FinalizeRetailPosSaleRequest $request): JsonResponse
    {
        $payload = $request->validated();
        try {
            $this->documents->assertPaymentProofForMethods(
                $request->user(),
                collect($payload['payments'] ?? [])->pluck('payment_method_reference_id')->filter()->values()->all(),
                $payload['attachment_tokens'] ?? [],
                'Retail checkout payment',
            );

            $result = $this->idempotency->execute(
                scope: 'sales.retail-pos.finalize',
                key: $payload['idempotency_key'],
                payload: $payload,
                callback: function () use ($request, $payload) {
                    $sale = $this->service->finalize($request->user(), $payload);
                    $this->documents->claimTokens(
                        $request->user(),
                        $payload['attachment_tokens'] ?? [],
                        'sales',
                        'SALE_CHECKOUT',
                        (int) $sale->id,
                        'CHECKOUT_PAYMENT_PROOF',
                    );
                    return ['status' => 201, 'body' => ['message' => 'Retail sale finalized.', 'sale' => $this->service->serialize($sale)]];
                },
                user: $request->user(),
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json($result['body'], $result['status']);
    }
}
