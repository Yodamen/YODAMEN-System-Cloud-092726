<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Models\InventoryStockReturn;
use App\Services\Inventory\StockReturnService;
use App\Services\Foundation\SupportingDocumentService;
use App\Services\MasterData\WorkflowReasonService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockReturnController extends Controller
{
    public function index(Request $request, StockReturnService $service): JsonResponse
    {
        return response()->json($service->index($request->user(),$request->only(['branch_id','status','from','to','search'])));
    }
    public function store(Request $request, StockReturnService $service, SupportingDocumentService $documents, WorkflowReasonService $reasons): JsonResponse
    {
        $data=$request->validate([
            'inventory_receipt_id'=>['required','integer'], 'branch_id'=>['required','integer'], 'return_date'=>['required','date'],
            'reason_reference_id'=>['required','integer'],'reason'=>['required','string','min:5','max:2000'],'lines'=>['required','array','min:1'],
            'lines.*.inventory_receipt_line_id'=>['required','integer','distinct'],'lines.*.quantity_returned'=>['required','numeric','gt:0'],
            'attachment_tokens'=>['sometimes','array','max:10'],'attachment_tokens.*'=>['required','uuid','distinct'],
        ]);
        try{$reasonReference=$reasons->resolve($request->user(),(int)$data['reason_reference_id'],'STOCK_RETURN');$data['reason_reference_id']=(int)$reasonReference->id;$row=DB::transaction(function() use($request,$service,$documents,$data){$row=$service->create($request->user(),$data);$documents->claimTokens($request->user(),$data['attachment_tokens']??[],'stock-returns','INVENTORY_STOCK_RETURN',(int)$row->id,'STOCK_RETURN_SUPPORT');return $row;});}catch(DomainException $e){return response()->json(['message'=>$e->getMessage()],422);}
        return response()->json(['message'=>'Stock Return submitted for approval.','return'=>$service->serialize($row)],201);
    }
    public function approve(Request $request, InventoryStockReturn $inventoryStockReturn, StockReturnService $service): JsonResponse
    {
        $data=$request->validate(['notes'=>['nullable','string','max:2000']]);
        try{$row=$service->approve($request->user(),$inventoryStockReturn,$data['notes']??null);}catch(DomainException $e){return response()->json(['message'=>$e->getMessage()],422);}
        return response()->json(['message'=>'Stock Return approved and posted.','return'=>$service->serialize($row)]);
    }
    public function reject(Request $request, InventoryStockReturn $inventoryStockReturn, StockReturnService $service, WorkflowReasonService $reasons): JsonResponse
    {
        $data=$request->validate(['review_reason_reference_id'=>['required','integer'],'notes'=>['required','string','min:3','max:2000']]);
        try{$reviewReason=$reasons->resolve($request->user(),(int)$data['review_reason_reference_id'],'APPROVAL_REJECTION');$row=$service->reject($request->user(),$inventoryStockReturn,$data['notes'],(int)$reviewReason->id);}catch(DomainException $e){return response()->json(['message'=>$e->getMessage()],422);}
        return response()->json(['message'=>'Stock Return rejected.','return'=>$service->serialize($row)]);
    }
    public function receiveRefund(Request $request, InventoryStockReturn $inventoryStockReturn, StockReturnService $service): JsonResponse
    {
        $data=$request->validate(['refund_date'=>['required','date'],'receiving_account_id'=>['required','integer'],'amount'=>['required','numeric','gt:0'],'reference'=>['nullable','string','max:180'],'attachment_tokens'=>['required','array','min:1','max:10'],'attachment_tokens.*'=>['required','uuid','distinct']]);
        try{$row=$service->receiveRefund($request->user(),$inventoryStockReturn,$data);}catch(DomainException $e){return response()->json(['message'=>$e->getMessage()],422);}
        return response()->json(['message'=>'Supplier refund received and posted.','return'=>$service->serialize($row)]);
    }
}
