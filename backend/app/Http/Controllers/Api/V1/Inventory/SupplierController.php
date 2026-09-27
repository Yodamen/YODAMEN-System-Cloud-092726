<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Services\Inventory\SupplierService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Request $request, SupplierService $service): JsonResponse
    {
        return response()->json($service->index($request->user(), $request->only(['supplier_id','channel','status'])));
    }

    public function store(Request $request, SupplierService $service): JsonResponse
    {
        $data = $this->validated($request);
        try {
            $supplier = $service->create($request->user(), $data);
            return response()->json(['message'=>'Supplier created.','supplier'=>$service->serialize($supplier)], 201);
        } catch (DomainException $e) { return response()->json(['message'=>$e->getMessage()], 422); }
    }

    public function update(Request $request, Supplier $supplier, SupplierService $service): JsonResponse
    {
        $data = $this->validated($request);
        try {
            $supplier = $service->update($request->user(), $supplier, $data);
            return response()->json(['message'=>'Supplier updated.','supplier'=>$service->serialize($supplier)]);
        } catch (DomainException $e) { return response()->json(['message'=>$e->getMessage()], 422); }
    }

    public function toggle(Request $request, Supplier $supplier, SupplierService $service): JsonResponse
    {
        try {
            $supplier = $service->toggle($request->user(), $supplier);
            return response()->json(['message'=>'Supplier status updated.','supplier'=>$service->serialize($supplier)]);
        } catch (DomainException $e) { return response()->json(['message'=>$e->getMessage()], 422); }
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'company_name'=>['required','string','max:180'],
            'code_name'=>['required','string','max:80'],
            'contact_person'=>['nullable','string','max:180'],
            'contact_number'=>['nullable','string','max:120'],
            'channel'=>['required','string','max:120'],
            'address'=>['nullable','string','max:4000'],
            'store_link'=>['nullable','string','max:1000'],
            'status'=>['required','in:ACTIVE,INACTIVE'],
            'item_ids'=>['nullable','array'],
            'item_ids.*'=>['integer','distinct'],
        ]);
    }
}
