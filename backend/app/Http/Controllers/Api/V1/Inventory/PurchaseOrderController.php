<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Services\Inventory\PurchaseOrderService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    public function index(Request $request,PurchaseOrderService $service): JsonResponse
    {
        return response()->json($service->index($request->user(),$request->only(['branch_id','supplier_id','status'])));
    }
    public function show(Request $request,int $id,PurchaseOrderService $service): JsonResponse
    {
        return response()->json(['order'=>$service->show($request->user(),$id)]);
    }
    private function rules(): array
    {
        return [
            'branch_id'=>['required','integer'], 'supplier_id'=>['required','integer'],
            'order_date'=>['required','date'], 'expected_delivery_date'=>['nullable','date','after_or_equal:order_date'],
            'reference_no'=>['nullable','string','max:180'], 'notes'=>['nullable','string','max:4000'],
            'lines'=>['required','array','min:1','max:200'],
            'lines.*.retail_product_id'=>['required','integer','distinct'],
            'lines.*.purchase_quantity'=>['required','numeric','gt:0'],
            'lines.*.purchase_uom'=>['required','string','max:40'],
            'lines.*.conversion_factor'=>['required','numeric','gt:0'],
            'lines.*.purchase_unit_cost'=>['required','numeric','min:0'],
        ];
    }
    public function store(Request $request,PurchaseOrderService $service): JsonResponse
    {
        try {return response()->json(['order'=>$service->save($request->user(),$request->validate($this->rules()))],201);}
        catch(DomainException $e){return response()->json(['message'=>$e->getMessage()],422);}
    }
    public function update(Request $request,int $id,PurchaseOrderService $service): JsonResponse
    {
        try {return response()->json(['order'=>$service->save($request->user(),$request->validate($this->rules()),$id)]);}
        catch(DomainException $e){return response()->json(['message'=>$e->getMessage()],422);}
    }
    public function submit(Request $request,int $id,PurchaseOrderService $service): JsonResponse
    {
        try{return response()->json(['order'=>$service->transition($request->user(),$id,'SUBMIT')]);}
        catch(DomainException $e){return response()->json(['message'=>$e->getMessage()],422);}
    }
    public function approve(Request $request,int $id,PurchaseOrderService $service): JsonResponse
    {
        try{return response()->json(['order'=>$service->transition($request->user(),$id,'APPROVE')]);}
        catch(DomainException $e){return response()->json(['message'=>$e->getMessage()],422);}
    }
    public function cancel(Request $request,int $id,PurchaseOrderService $service): JsonResponse
    {
        $data=$request->validate(['reason'=>['required','string','min:5','max:2000']]);
        try{return response()->json(['order'=>$service->transition($request->user(),$id,'CANCEL',$data['reason'])]);}
        catch(DomainException $e){return response()->json(['message'=>$e->getMessage()],422);}
    }
    public function receive(Request $request,int $id,PurchaseOrderService $service): JsonResponse
    {
        $data=$request->validate([
            'received_date'=>['required','date'], 'reference_no'=>['nullable','string','max:180'],
            'notes'=>['nullable','string','max:4000'],
            'attachment_tokens'=>['required','array','min:1','max:10'],
            'attachment_tokens.*'=>['required','uuid','distinct'],
            'lines'=>['required','array','min:1','max:200'],
            'lines.*.purchase_order_line_id'=>['required','integer','distinct'],
            'lines.*.purchase_quantity'=>['required','numeric','gt:0'],
        ]);
        try{return response()->json($service->receive($request->user(),$id,$data),201);}
        catch(DomainException $e){return response()->json(['message'=>$e->getMessage()],422);}
    }
}
