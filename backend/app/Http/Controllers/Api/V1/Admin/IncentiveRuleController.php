<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\SaveIncentiveRuleRequest;
use App\Services\Incentives\IncentiveRuleService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IncentiveRuleController extends Controller
{
    public function index(Request $request, IncentiveRuleService $service): JsonResponse
    {
        $businessId=(int)$request->user()->business_id;
        return response()->json(['rules'=>$service->list($businessId),'options'=>$service->formOptions($businessId),'phase'=>['automatic_earning'=>true,'payroll_integrated'=>true,'stacking_supported'=>true]]);
    }
    public function store(SaveIncentiveRuleRequest $request, IncentiveRuleService $service): JsonResponse
    {
        try{$rule=$service->create($request->user(),$request->validated());}catch(DomainException $e){return response()->json(['message'=>$e->getMessage()],422);}
        return response()->json(['message'=>'Incentive rule created.','rule'=>$service->serialize($rule)],201);
    }
    public function update(SaveIncentiveRuleRequest $request,string $incentiveRuleId,IncentiveRuleService $service): JsonResponse
    {
        try{$rule=$service->updateById($request->user(),(int)$incentiveRuleId,$request->validated());}catch(DomainException $e){return response()->json(['message'=>$e->getMessage()],422);}
        return response()->json(['message'=>'Incentive rule updated.','rule'=>$service->serialize($rule)]);
    }
    public function toggle(Request $request,string $incentiveRuleId,IncentiveRuleService $service): JsonResponse
    {
        $rule=$service->toggleById($request->user(),(int)$incentiveRuleId);
        return response()->json(['message'=>$rule->status==='ACTIVE'?'Incentive rule activated.':'Incentive rule deactivated.','rule'=>$service->serialize($rule)]);
    }
}
