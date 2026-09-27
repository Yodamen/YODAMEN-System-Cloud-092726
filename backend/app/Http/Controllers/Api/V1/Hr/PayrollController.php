<?php
namespace App\Http\Controllers\Api\V1\Hr;

use App\Http\Controllers\Controller;
use App\Models\PayrollAdjustment;
use App\Models\EmployeeCashAdvance;
use App\Models\PayrollRun;
use App\Models\ReversalApprovalRequest;
use App\Services\Hr\PayrollService;
use App\Services\Accounting\ReversalApprovalService;
use App\Services\Foundation\SupportingDocumentService;
use App\Services\MasterData\WorkflowReasonCatalog;
use App\Services\MasterData\WorkflowReasonService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PayrollController extends Controller
{
    public function index(Request $request, PayrollService $service): JsonResponse { return response()->json($service->list($request->user())); }
    public function show(Request $request, PayrollRun $payrollRun, PayrollService $service): JsonResponse { return response()->json($service->show($request->user(),$payrollRun)); }
    public function store(Request $request, PayrollService $service): JsonResponse
    {
        $data=$request->validate(['period_start'=>['required','date_format:Y-m-d'],'period_end'=>['required','date_format:Y-m-d'],'pay_date'=>['required','date_format:Y-m-d'],'branch_id'=>['nullable','integer'],'notes'=>['nullable','string','max:2000']]);
        try{$run=$service->create($request->user(),$data);}catch(DomainException $e){return response()->json(['message'=>$e->getMessage()],422);}
        return response()->json(['message'=>'Payroll Draft created.','run'=>$service->serializeRun($run,true)],201);
    }
    public function recalculate(Request $request, PayrollRun $payrollRun, PayrollService $service):JsonResponse { try{$run=$service->recalculate($request->user(),$payrollRun);}catch(DomainException $e){return response()->json(['message'=>$e->getMessage()],422);} return response()->json(['message'=>'Payroll Draft recalculated.','run'=>$service->serializeRun($run,true)]); }
    public function approve(Request $request, PayrollRun $payrollRun, PayrollService $service):JsonResponse { try{$run=$service->approve($request->user(),$payrollRun);}catch(DomainException $e){return response()->json(['message'=>$e->getMessage()],422);} return response()->json(['message'=>'Payroll approved.','run'=>$service->serializeRun($run,true)]); }
    public function markPaid(Request $request, PayrollRun $payrollRun, PayrollService $service): JsonResponse
    {
        $data=$request->validate([
            'funding_account_id'=>['nullable','integer'],
            'reference'=>['nullable','string','max:160'],
            'funding_splits'=>['nullable','array','max:5'],
            'funding_splits.*.funding_account_id'=>['required_with:funding_splits','integer','distinct'],
            'funding_splits.*.amount'=>['required_with:funding_splits','numeric','min:0.01'],
            'funding_splits.*.reference'=>['nullable','string','max:160'],
        ]);
        try{
            $run=$service->markPaid(
                $request->user(),
                $payrollRun,
                $data['funding_account_id']??null,
                $data['reference']??null,
                $data['funding_splits']??null,
            );
        }catch(DomainException $e){
            return response()->json(['message'=>$e->getMessage()],422);
        }
        return response()->json(['message'=>'Payroll marked Paid.','run'=>$service->serializeRun($run,true)]);
    }
    public function cancel(Request $request, PayrollRun $payrollRun, PayrollService $service):JsonResponse { try{$run=$service->cancelDraft($request->user(),$payrollRun);}catch(DomainException $e){return response()->json(['message'=>$e->getMessage()],422);} return response()->json(['message'=>'Payroll Draft cancelled.','run'=>$service->serializeRun($run)]); }

    public function waiveCashAdvance(Request $request, PayrollRun $payrollRun, EmployeeCashAdvance $cashAdvance, PayrollService $service): JsonResponse
    {
        $data=$request->validate(['reason'=>['required','string','max:2000']]);
        try{$run=$service->waiveCashAdvance($request->user(),$payrollRun,$cashAdvance,$data['reason']);}
        catch(DomainException $e){return response()->json(['message'=>$e->getMessage()],422);}
        return response()->json(['message'=>'Cash advance deduction waived for this Payroll Draft.','run'=>$service->serializeRun($run,true)]);
    }

    public function restoreCashAdvance(Request $request, PayrollRun $payrollRun, EmployeeCashAdvance $cashAdvance, PayrollService $service): JsonResponse
    {
        $data=$request->validate(['reason'=>['required','string','max:2000']]);
        try{$run=$service->restoreCashAdvance($request->user(),$payrollRun,$cashAdvance,$data['reason']);}
        catch(DomainException $e){return response()->json(['message'=>$e->getMessage()],422);}
        return response()->json(['message'=>'Cash advance deduction restored for this Payroll Draft.','run'=>$service->serializeRun($run,true)]);
    }

    public function adjustment(Request $request, PayrollService $service, SupportingDocumentService $documents):JsonResponse
    {
        $data=$request->validate(['personnel_id'=>['required','integer'],'effective_date'=>['required','date_format:Y-m-d'],'adjustment_type'=>['required','in:ALLOWANCE,BONUS,DEDUCTION,CORRECTION,OTHER'],'amount'=>['required','numeric','not_in:0'],'description'=>['required','string','max:255'],'reason'=>['required','string','max:2000'],'attachment_tokens'=>['sometimes','array','max:10'],'attachment_tokens.*'=>['required','uuid','distinct']]);
        try{$row=DB::transaction(function() use($request,$service,$documents,$data){$row=$service->createAdjustment($request->user(),$data);$documents->claimTokens($request->user(),$data['attachment_tokens']??[],'payroll','PAYROLL_ADJUSTMENT',(int)$row->id,'PAYROLL_ADJUSTMENT_SUPPORT');return $row;});}catch(DomainException $e){return response()->json(['message'=>$e->getMessage()],422);}
        return response()->json(['message'=>'Payroll adjustment created.','adjustment'=>['id'=>$row->id,'code'=>$row->code,'status'=>$row->status]],201);
    }
    public function reverseAdjustment(Request $request, PayrollAdjustment $payrollAdjustment, PayrollService $service, ReversalApprovalService $reversalApprovals, SupportingDocumentService $documents, WorkflowReasonService $reasons):JsonResponse
    {
        $data=$request->validate(['reason_reference_id'=>['required','integer'],'reason'=>['required','string','min:5','max:1000'],'attachment_tokens'=>['sometimes','array','max:10'],'attachment_tokens.*'=>['required','uuid','distinct']]);
        $reasonReference=$reasons->resolve($request->user(),(int)$data['reason_reference_id'],WorkflowReasonCatalog::PAYROLL_ADJUSTMENT_REVERSAL);
        try{
            if((int)$payrollAdjustment->business_id!==(int)$request->user()->business_id) abort(404);
            if($payrollAdjustment->status!=='OPEN') throw new DomainException('Only Open payroll adjustments can be submitted for reversal.');
            $row=DB::transaction(function() use($request,$reversalApprovals,$documents,$payrollAdjustment,$data,$reasonReference){$row=$reversalApprovals->request($request->user(),'payroll','PAYROLL_ADJUSTMENT',(int)$payrollAdjustment->id,$payrollAdjustment->code,$data['reason'],(int)$reasonReference->id);$documents->claimTokens($request->user(),$data['attachment_tokens']??[],'payroll','PAYROLL_ADJUSTMENT_REVERSAL',(int)$row->id,'PAYROLL_ADJUSTMENT_REVERSAL_SUPPORT');return $row;});
            return response()->json(['message'=>'Payroll adjustment reversal submitted for approval.','reversal_request'=>ReversalApprovalService::serialize($row)],201);
        }catch(DomainException $e){return response()->json(['message'=>$e->getMessage()],422);}
    }

    public function approveAdjustmentReversal(Request $request, ReversalApprovalRequest $reversalApprovalRequest, PayrollService $service, ReversalApprovalService $reversalApprovals):JsonResponse
    {
        $data=$request->validate(['notes'=>['nullable','string','max:1000']]);
        try{
            $approved=$reversalApprovals->approve($request->user(),$reversalApprovalRequest,'PAYROLL_ADJUSTMENT',function(ReversalApprovalRequest $approval) use($request,$service){
                $row=PayrollAdjustment::query()->where('business_id',$request->user()->business_id)->findOrFail($approval->source_id);
                return $service->reverseAdjustment($request->user(),$row,$approval->reason);
            },$data['notes']??null);
            return response()->json(['message'=>'Payroll adjustment reversal approved.','reversal_request'=>ReversalApprovalService::serialize($approved['request']),'adjustment'=>['id'=>$approved['result']->id,'status'=>$approved['result']->status]]);
        }catch(DomainException $e){return response()->json(['message'=>$e->getMessage()],422);}
    }

    public function rejectAdjustmentReversal(Request $request, ReversalApprovalRequest $reversalApprovalRequest, ReversalApprovalService $reversalApprovals, WorkflowReasonService $reasons):JsonResponse
    {
        $data=$request->validate(['review_reason_reference_id'=>['required','integer'],'notes'=>['required','string','min:3','max:1000']]);
        $reviewReason=$reasons->resolve($request->user(),(int)$data['review_reason_reference_id'],WorkflowReasonCatalog::APPROVAL_REJECTION);
        try{$row=$reversalApprovals->reject($request->user(),$reversalApprovalRequest,'PAYROLL_ADJUSTMENT',$data['notes'],(int)$reviewReason->id); return response()->json(['message'=>'Payroll adjustment reversal request rejected.','reversal_request'=>ReversalApprovalService::serialize($row)]);}
        catch(DomainException $e){return response()->json(['message'=>$e->getMessage()],422);}
    }
}
