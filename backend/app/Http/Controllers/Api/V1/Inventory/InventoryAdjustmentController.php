<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Models\InventoryAdjustmentRequest;
use App\Models\RetailProduct;
use App\Services\Inventory\InventoryAdjustmentService;
use App\Services\Foundation\SupportingDocumentService;
use App\Services\MasterData\WorkflowReasonService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryAdjustmentController extends Controller
{
    public function store(Request $request, InventoryAdjustmentService $service, SupportingDocumentService $documents, WorkflowReasonService $reasons): JsonResponse
    {
        $data = $request->validate([
            'retail_product_id' => ['required', 'integer', 'exists:retail_products,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'quantity_change' => ['required', 'numeric', 'not_in:0', 'min:-100000000', 'max:100000000'],
            'reason_reference_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'attachment_tokens' => ['sometimes','array','max:10'],
            'attachment_tokens.*' => ['required','uuid','distinct'],
        ]);

        try {
            $reasonReference = $reasons->resolve($request->user(), (int) $data['reason_reference_id'], 'INVENTORY_ADJUSTMENT');
            $data['reason_reference_id'] = (int) $reasonReference->id;
            $product = RetailProduct::query()->findOrFail($data['retail_product_id']);
            $adjustment = DB::transaction(function () use ($request,$product,$data,$service,$documents) { $row=$service->create($request->user(),$product,$data); $documents->claimTokens($request->user(),$data['attachment_tokens']??[],'inventory-adjustments','INVENTORY_ADJUSTMENT',(int)$row->id,'INVENTORY_ADJUSTMENT_SUPPORT'); return $row; });
            return response()->json(['message' => 'Stock adjustment submitted for approval.', 'request' => $this->serialize($adjustment)], 201);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }


    public function requestReversal(Request $request, InventoryAdjustmentRequest $inventoryAdjustmentRequest, InventoryAdjustmentService $service, SupportingDocumentService $documents, WorkflowReasonService $reasons): JsonResponse
    {
        $data = $request->validate(['reason_reference_id'=>['required','integer'],'reason' => ['required', 'string', 'min:5', 'max:2000'],'attachment_tokens'=>['sometimes','array','max:10'],'attachment_tokens.*'=>['required','uuid','distinct']]);
        try {
            $reasonReference=$reasons->resolve($request->user(),(int)$data['reason_reference_id'],'INVENTORY_ADJUSTMENT_REVERSAL');
            $updated = DB::transaction(function () use($request,$inventoryAdjustmentRequest,$data,$service,$documents,$reasonReference){ $row=$service->requestReversal($request->user(),$inventoryAdjustmentRequest,$data['reason'],(int)$reasonReference->id); $documents->claimTokens($request->user(),$data['attachment_tokens']??[],'inventory-adjustments','INVENTORY_ADJUSTMENT',(int)$row->id,'INVENTORY_ADJUSTMENT_REVERSAL_SUPPORT'); return $row; });
            return response()->json(['message' => 'Stock adjustment reversal submitted for approval.', 'request' => $this->serialize($updated)], 201);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function approve(Request $request, InventoryAdjustmentRequest $inventoryAdjustmentRequest, InventoryAdjustmentService $service): JsonResponse
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);
        try {
            $updated = $service->approve($request->user(), $inventoryAdjustmentRequest, $data['notes'] ?? null);
            return response()->json(['message' => 'Stock adjustment approved and posted.', 'request' => $this->serialize($updated)]);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function reject(Request $request, InventoryAdjustmentRequest $inventoryAdjustmentRequest, InventoryAdjustmentService $service, WorkflowReasonService $reasons): JsonResponse
    {
        $data = $request->validate(['review_reason_reference_id'=>['required','integer'],'notes' => ['required', 'string', 'min:3', 'max:2000']]);
        try {
            $reviewReason=$reasons->resolve($request->user(),(int)$data['review_reason_reference_id'],'APPROVAL_REJECTION');
            $updated = $service->reject($request->user(), $inventoryAdjustmentRequest, $data['notes'], (int)$reviewReason->id);
            return response()->json(['message' => 'Stock adjustment rejected.', 'request' => $this->serialize($updated)]);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    private function serialize(InventoryAdjustmentRequest $request): array
    {
        $request->loadMissing(['branch', 'product', 'requester', 'reviewer', 'reasonReference', 'reviewReasonReference']);
        return [
            'id' => $request->id,
            'code' => $request->code,
            'request_type' => strtoupper((string) ($request->request_type ?: 'ADJUSTMENT')),
            'reversal_of_id' => $request->reversal_of_id,
            'status' => $request->status,
            'quantity_change' => (float) $request->quantity_change,
            'reason' => $request->reason,
            'reason_category' => $request->reasonReference ? ['id'=>(int)$request->reasonReference->id,'code'=>$request->reasonReference->code,'name'=>$request->reasonReference->display_value] : null,
            'review_reason_category' => $request->reviewReasonReference ? ['id'=>(int)$request->reviewReasonReference->id,'code'=>$request->reviewReasonReference->code,'name'=>$request->reviewReasonReference->display_value] : null,
            'review_notes' => $request->review_notes,
            'created_at' => $request->created_at?->toIso8601String(),
            'reviewed_at' => $request->reviewed_at?->toIso8601String(),
            'branch' => ['id' => $request->branch?->id, 'name' => $request->branch?->name],
            'product' => ['id' => $request->product?->id, 'sku' => $request->product?->sku, 'name' => $request->product?->name, 'unit' => $request->product?->unit],
            'requested_by' => $request->requester?->display_name,
            'reviewed_by' => $request->reviewer?->display_name,
        ];
    }
}
