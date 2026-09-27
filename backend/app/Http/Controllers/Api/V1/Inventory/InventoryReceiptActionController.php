<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Models\InventoryReceipt;
use App\Models\InventoryReceiptActionRequest;
use App\Models\InventoryReceiptLine;
use App\Services\Inventory\InventoryReceiptActionService;
use App\Services\Foundation\SupportingDocumentService;
use App\Services\MasterData\WorkflowReasonService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryReceiptActionController extends Controller
{
    public function requestReversal(Request $request, InventoryReceipt $inventoryReceipt, InventoryReceiptActionService $service, SupportingDocumentService $documents, WorkflowReasonService $reasons): JsonResponse
    {
        $data=$request->validate(['reason_reference_id'=>['required','integer'],'reason'=>['required','string','min:5','max:2000'],'attachment_tokens'=>['sometimes','array','max:10'],'attachment_tokens.*'=>['required','uuid','distinct']]);
        try {$reasonReference=$reasons->resolve($request->user(),(int)$data['reason_reference_id'],'STOCK_RECEIPT_REVERSAL');$row=DB::transaction(function() use($request,$inventoryReceipt,$service,$documents,$data,$reasonReference){$row=$service->requestReversal($request->user(),$inventoryReceipt,$data['reason'],(int)$reasonReference->id);$documents->claimTokens($request->user(),$data['attachment_tokens']??[],'stock-received','INVENTORY_RECEIPT_ACTION',(int)$row->id,'STOCK_RECEIVED_REVERSAL_SUPPORT');return $row;});}
        catch(DomainException $e){return response()->json(['message'=>$e->getMessage()],422);}
        return response()->json(['message'=>'Stock receipt reversal submitted for approval.','request'=>$this->serialize($row)],201);
    }

    public function requestCorrection(Request $request, InventoryReceipt $inventoryReceipt, InventoryReceiptLine $inventoryReceiptLine, InventoryReceiptActionService $service, SupportingDocumentService $documents, WorkflowReasonService $reasons): JsonResponse
    {
        $data=$request->validate([
            'corrected_purchase_quantity'=>['required','numeric','min:0','max:100000000'],
            'corrected_purchase_unit_cost'=>['required','numeric','min:0','max:999999999999.99'],
            'reason_reference_id'=>['required','integer'],
            'reason'=>['required','string','min:5','max:2000'],
            'attachment_tokens'=>['sometimes','array','max:10'],
            'attachment_tokens.*'=>['required','uuid','distinct'],
        ]);
        try {$reasonReference=$reasons->resolve($request->user(),(int)$data['reason_reference_id'],'STOCK_RECEIPT_CORRECTION');$row=DB::transaction(function() use($request,$inventoryReceipt,$inventoryReceiptLine,$service,$documents,$data,$reasonReference){$row=$service->requestCorrection($request->user(),$inventoryReceipt,$inventoryReceiptLine,(float)$data['corrected_purchase_quantity'],(float)$data['corrected_purchase_unit_cost'],$data['reason'],(int)$reasonReference->id);$documents->claimTokens($request->user(),$data['attachment_tokens']??[],'stock-received','INVENTORY_RECEIPT_ACTION',(int)$row->id,'STOCK_RECEIVED_CORRECTION_SUPPORT');return $row;});}
        catch(DomainException $e){return response()->json(['message'=>$e->getMessage()],422);}
        return response()->json(['message'=>'Stock receipt item correction submitted for approval.','request'=>$this->serialize($row)],201);
    }

    public function approveCorrection(Request $request, InventoryReceiptActionRequest $inventoryReceiptActionRequest, InventoryReceiptActionService $service): JsonResponse
    {
        return $this->approveTyped($request, $inventoryReceiptActionRequest, $service, 'CORRECTION');
    }

    public function approveReversal(Request $request, InventoryReceiptActionRequest $inventoryReceiptActionRequest, InventoryReceiptActionService $service): JsonResponse
    {
        return $this->approveTyped($request, $inventoryReceiptActionRequest, $service, 'REVERSAL');
    }

    public function rejectCorrection(Request $request, InventoryReceiptActionRequest $inventoryReceiptActionRequest, InventoryReceiptActionService $service): JsonResponse
    {
        return $this->rejectTyped($request, $inventoryReceiptActionRequest, $service, 'CORRECTION');
    }

    public function rejectReversal(Request $request, InventoryReceiptActionRequest $inventoryReceiptActionRequest, InventoryReceiptActionService $service): JsonResponse
    {
        return $this->rejectTyped($request, $inventoryReceiptActionRequest, $service, 'REVERSAL');
    }


    public function receiveRefund(Request $request, InventoryReceiptActionRequest $inventoryReceiptActionRequest, InventoryReceiptActionService $service): JsonResponse
    {
        $data = $request->validate([
            'refund_date' => ['required','date'],
            'receiving_account_id' => ['required','integer'],
            'amount' => ['required','numeric','gt:0'],
            'reference' => ['nullable','string','max:180'],
            'attachment_tokens' => ['required','array','min:1','max:10'],
            'attachment_tokens.*' => ['required','uuid','distinct'],
        ]);
        try {
            $row = $service->receiveRefund($request->user(), $inventoryReceiptActionRequest, $data);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['message' => 'Supplier refund received and posted.', 'request' => $this->serialize($row)]);
    }

    private function approveTyped(Request $request, InventoryReceiptActionRequest $row, InventoryReceiptActionService $service, string $expectedType): JsonResponse
    {
        if (strtoupper((string) $row->action_type) !== $expectedType) {
            return response()->json(['message' => 'This approval endpoint does not match the request type.'], 422);
        }
        $data = $request->validate(['notes' => ['nullable','string','max:2000']]);
        try {
            $approved = $service->approve($request->user(), $row, $data['notes'] ?? null);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['message' => 'Stock receipt request approved and posted.', 'request' => $this->serialize($approved)]);
    }

    private function rejectTyped(Request $request, InventoryReceiptActionRequest $row, InventoryReceiptActionService $service, string $expectedType): JsonResponse
    {
        if (strtoupper((string) $row->action_type) !== $expectedType) {
            return response()->json(['message' => 'This rejection endpoint does not match the request type.'], 422);
        }
        $data = $request->validate(['review_reason_reference_id'=>['required','integer'],'notes' => ['required','string','min:3','max:2000']]);
        try {
            $reviewReason = app(WorkflowReasonService::class)->resolve($request->user(), (int)$data['review_reason_reference_id'], 'APPROVAL_REJECTION');
            $rejected = $service->reject($request->user(), $row, $data['notes'], (int)$reviewReason->id);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['message' => 'Stock receipt request rejected.', 'request' => $this->serialize($rejected)]);
    }

    public function serialize(InventoryReceiptActionRequest $row): array
    {
        $row->loadMissing(['receipt.branch','receipt.supplier','lines.receiptLine.product','requester','reviewer','reasonReference','reviewReasonReference','refunds.receivingAccount','refunds.creator']);
        return [
            'id'=>$row->id,'code'=>$row->code,'action_type'=>$row->action_type,'status'=>$row->status,'reason'=>$row->reason,
            'reason_category'=>$row->reasonReference?['id'=>(int)$row->reasonReference->id,'code'=>$row->reasonReference->code,'name'=>$row->reasonReference->display_value]:null,
            'review_reason_category'=>$row->reviewReasonReference?['id'=>(int)$row->reviewReasonReference->id,'code'=>$row->reviewReasonReference->code,'name'=>$row->reviewReasonReference->display_value]:null,
            'requested_by'=>$row->requester?->display_name,'reviewed_by'=>$row->reviewer?->display_name,
            'reviewed_at'=>$row->reviewed_at?->toIso8601String(),'review_notes'=>$row->review_notes,'created_at'=>$row->created_at?->toIso8601String(),
            'supplier_refund_receivable'=>(float)($row->supplier_refund_receivable ?? 0),
            'refund_received'=>round((float)$row->refunds->sum('amount'),2),
            'refund_remaining'=>round(max(0,(float)($row->supplier_refund_receivable ?? 0)-(float)$row->refunds->sum('amount')),2),
            'refunds'=>$row->refunds->map(fn($refund)=>['id'=>(int)$refund->id,'refund_date'=>$refund->refund_date?->toDateString(),'amount'=>(float)$refund->amount,'reference'=>$refund->reference,'receiving_account'=>$refund->receivingAccount?->name,'received_by'=>$refund->creator?->display_name])->values(),
            'receipt'=>['id'=>$row->receipt?->id,'code'=>$row->receipt?->code],
            'lines'=>$row->lines->map(fn($line)=>[
                'id'=>$line->id,'inventory_receipt_line_id'=>$line->inventory_receipt_line_id,
                'name'=>$line->receiptLine?->product?->name,'sku'=>$line->receiptLine?->product?->sku,
                'before_purchase_quantity'=>(float)$line->before_purchase_quantity,'before_purchase_unit_cost'=>(float)$line->before_purchase_unit_cost,
                'corrected_purchase_quantity'=>(float)$line->corrected_purchase_quantity,'corrected_purchase_unit_cost'=>(float)$line->corrected_purchase_unit_cost,
                'quantity_delta'=>(float)$line->quantity_delta,'value_delta'=>(float)$line->value_delta,
            ])->values(),
        ];
    }
}
