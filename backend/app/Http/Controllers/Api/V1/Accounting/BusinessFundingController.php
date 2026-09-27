<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Models\BusinessFundingTransaction;
use App\Models\ReversalApprovalRequest;
use App\Services\Accounting\BusinessFundingService;
use App\Services\Accounting\ReversalApprovalService;
use App\Services\Foundation\SupportingDocumentService;
use App\Services\MasterData\WorkflowReasonCatalog;
use App\Services\MasterData\WorkflowReasonService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BusinessFundingController extends Controller
{
    public function __construct(private readonly BusinessFundingService $service, private readonly ReversalApprovalService $reversalApprovals, private readonly SupportingDocumentService $documents) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->service->list($request->user()));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['nullable','integer','exists:branches,id'],
            'funding_date' => ['required','date_format:Y-m-d'],
            'funding_type' => ['required','in:CAPITAL_CONTRIBUTION,OWNER_LOAN'],
            'cash_account_id' => ['required','integer','exists:chart_of_accounts,id'],
            'amount' => ['required','numeric','gt:0','max:999999999999.99'],
            'reference' => ['nullable','string','max:160'],
            'notes' => ['nullable','string','max:3000'],
            'attachment_tokens' => ['sometimes','array','max:10'],
            'attachment_tokens.*' => ['required','uuid','distinct'],
        ]);
        try {
            return response()->json(['funding' => DB::transaction(function () use($request,$data){ $row=$this->service->create($request->user(),$data); $this->documents->claimTokens($request->user(),$data['attachment_tokens']??[],'cash-management','BUSINESS_FUNDING',(int)$row->id,'BUSINESS_FUNDING_SUPPORT'); return $row; })], 201);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function reverse(Request $request, BusinessFundingTransaction $businessFundingTransaction, WorkflowReasonService $reasons): JsonResponse
    {
        $data = $request->validate(['reason_reference_id' => ['required','integer'], 'reason' => ['required','string','min:5','max:1000']]);
        $reasonReference = $reasons->resolve($request->user(), (int) $data['reason_reference_id'], WorkflowReasonCatalog::BUSINESS_FUNDING_REVERSAL);
        try {
            if ((int)$businessFundingTransaction->business_id !== (int)$request->user()->business_id) abort(404);
            if ($businessFundingTransaction->status !== 'POSTED') throw new DomainException('Only posted business funding can be submitted for reversal.');
            $row = $this->reversalApprovals->request($request->user(),'cash-management','BUSINESS_FUNDING',(int)$businessFundingTransaction->id,$businessFundingTransaction->code,$data['reason'],(int)$reasonReference->id);
            return response()->json(['message'=>'Business funding reversal submitted for approval.','reversal_request'=>ReversalApprovalService::serialize($row)],201);
        } catch (DomainException $e) { return response()->json(['message'=>$e->getMessage()],422); }
    }

    public function approveReversal(Request $request, ReversalApprovalRequest $reversalApprovalRequest): JsonResponse
    {
        $data=$request->validate(['notes'=>['nullable','string','max:1000']]);
        try {
            $approved=$this->reversalApprovals->approve($request->user(),$reversalApprovalRequest,'BUSINESS_FUNDING',function(ReversalApprovalRequest $approval) use ($request){
                $row=BusinessFundingTransaction::query()->where('business_id',$request->user()->business_id)->findOrFail($approval->source_id);
                return $this->service->reverse($request->user(),$row,$approval->reason);
            },$data['notes']??null);
            return response()->json(['message'=>'Business funding reversal approved and posted.','reversal_request'=>ReversalApprovalService::serialize($approved['request']),'funding'=>$approved['result']]);
        } catch (DomainException $e) { return response()->json(['message'=>$e->getMessage()],422); }
    }

    public function rejectReversal(Request $request, ReversalApprovalRequest $reversalApprovalRequest, WorkflowReasonService $reasons): JsonResponse
    {
        $data=$request->validate(['review_reason_reference_id'=>['required','integer'], 'notes'=>['required','string','min:3','max:1000']]);
        $reviewReason = $reasons->resolve($request->user(), (int) $data['review_reason_reference_id'], WorkflowReasonCatalog::APPROVAL_REJECTION);
        try { $row=$this->reversalApprovals->reject($request->user(),$reversalApprovalRequest,'BUSINESS_FUNDING',$data['notes'],(int)$reviewReason->id); return response()->json(['message'=>'Business funding reversal request rejected.','reversal_request'=>ReversalApprovalService::serialize($row)]); }
        catch (DomainException $e) { return response()->json(['message'=>$e->getMessage()],422); }
    }

}
