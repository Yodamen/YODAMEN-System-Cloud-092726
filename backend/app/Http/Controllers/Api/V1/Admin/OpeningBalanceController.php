<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Accounting\OpeningBalanceService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OpeningBalanceController extends Controller
{
    public function index(Request $request, OpeningBalanceService $service): JsonResponse
    {
        try {
            return response()->json($service->bootstrap($request->user(), $request->integer('branch_id') ?: null));
        } catch (DomainException $e) {
            return response()->json(['message'=>$e->getMessage()], 422);
        }
    }

    public function store(Request $request, OpeningBalanceService $service): JsonResponse
    {
        $data = $request->validate([
            'branch_id'=>['required','integer'],
            'cutover_date'=>['required','date','before_or_equal:today'],
            'cash_balances'=>['nullable','array','max:30'],
            'cash_balances.*.account_id'=>['required_with:cash_balances','integer'],
            'cash_balances.*.amount'=>['required_with:cash_balances','numeric','min:0'],
            'inventory_balances'=>['nullable','array','max:1000'],
            'inventory_balances.*.retail_product_id'=>['required_with:inventory_balances','integer'],
            'inventory_balances.*.quantity'=>['required_with:inventory_balances','numeric','min:0'],
            'inventory_balances.*.unit_cost'=>['required_with:inventory_balances','numeric','min:0'],
            'customer_receivables'=>['nullable','array','max:1000'],
            'customer_receivables.*.customer_id'=>['required_with:customer_receivables','integer'],
            'customer_receivables.*.amount'=>['required_with:customer_receivables','numeric','min:0'],
            'customer_receivables.*.reference'=>['nullable','string','max:150'],
            'customer_receivables.*.notes'=>['nullable','string','max:1000'],
            'active_packages'=>['nullable','array','max:1000'],
            'active_packages.*.customer_id'=>['required_with:active_packages','integer'],
            'active_packages.*.service_id'=>['required_with:active_packages','integer'],
            'active_packages.*.remaining_sessions'=>['required_with:active_packages','integer','min:1','max:1000'],
            'active_packages.*.remaining_service_value'=>['required_with:active_packages','numeric','min:0'],
            'active_packages.*.outstanding_balance'=>['nullable','numeric','min:0'],
            'active_packages.*.expiry_date'=>['nullable','date','after_or_equal:cutover_date'],
            'active_packages.*.notes'=>['nullable','string','max:1000'],
            'notes'=>['nullable','string','max:2000'],
        ]);

        try {
            $opening = $service->post($request->user(), $data);
            return response()->json(['message'=>'Opening balances posted. Cash, inventory, customer balances, and active packages now start from the cutover snapshot.','opening_balance'=>$service->serialize($opening)], 201);
        } catch (DomainException $e) {
            return response()->json(['message'=>$e->getMessage()], 422);
        }
    }
}
