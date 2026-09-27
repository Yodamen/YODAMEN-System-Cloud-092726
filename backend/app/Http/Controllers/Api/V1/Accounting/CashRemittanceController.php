<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Models\CashRemittance;
use App\Models\ReversalApprovalRequest;
use App\Services\Accounting\CashRemittanceService;
use App\Services\Accounting\ReversalApprovalService;
use App\Services\Foundation\SupportingDocumentService;
use App\Services\MasterData\WorkflowReasonCatalog;
use App\Services\MasterData\WorkflowReasonService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashRemittanceController extends Controller
{
    public function __construct(private readonly CashRemittanceService $service, private readonly ReversalApprovalService $reversalApprovals, private readonly SupportingDocumentService $documents) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['nullable','date_format:Y-m-d'],
            'to' => ['nullable','date_format:Y-m-d'],
            'branch_id' => ['nullable','integer'],
        ]);
        return response()->json($this->service->list($request->user(), $filters));
    }

    public function daySummary(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required','integer'],
            'business_date' => ['required','date_format:Y-m-d'],
        ]);
        try {
            return response()->json(['summary' => $this->service->daySummary($request->user(), (int) $data['branch_id'], $data['business_date'])]);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required','integer','exists:branches,id'],
            'business_date' => ['required','date_format:Y-m-d'],
            'remittance_date' => ['required','date_format:Y-m-d'],
            'received_by_user_id' => ['required','integer','exists:users,id'],
            'amount' => ['required','numeric','gt:0','max:999999999999.99'],
            'reference' => ['nullable','string','max:160'],
            'notes' => ['nullable','string','max:3000'],
            'attachment_tokens' => ['sometimes','array','max:10'],
            'attachment_tokens.*' => ['required','uuid','distinct'],
        ]);
        try {
            return response()->json(['remittance' => DB::transaction(function () use($request,$data){ $row=$this->service->create($request->user(),$data); $this->documents->claimTokens($request->user(),$data['attachment_tokens']??[],'cash-remittances','CASH_REMITTANCE',(int)$row->id,'CASH_REMITTANCE_SUPPORT'); return $row; })], 201);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function reverse(Request $request, CashRemittance $cashRemittance, WorkflowReasonService $reasons): JsonResponse
    {
        $data = $request->validate(['reason_reference_id' => ['required','integer'], 'reason' => ['required','string','min:5','max:1000']]);
        $reasonReference = $reasons->resolve($request->user(), (int) $data['reason_reference_id'], WorkflowReasonCatalog::CASH_REMITTANCE_REVERSAL);
        try {
            if ((int)$cashRemittance->business_id !== (int)$request->user()->business_id) abort(404);
            if ($cashRemittance->status !== 'POSTED') throw new DomainException('Only posted cash remittances can be submitted for reversal.');
            $row = $this->reversalApprovals->request($request->user(),'cash-deposits','CASH_REMITTANCE',(int)$cashRemittance->id,$cashRemittance->code,$data['reason'],(int)$reasonReference->id);
            return response()->json(['message'=>'Cash remittance reversal submitted for approval.','reversal_request'=>ReversalApprovalService::serialize($row)],201);
        } catch (DomainException $e) { return response()->json(['message'=>$e->getMessage()],422); }
    }

    public function approveReversal(Request $request, ReversalApprovalRequest $reversalApprovalRequest): JsonResponse
    {
        $data=$request->validate(['notes'=>['nullable','string','max:1000']]);
        try {
            $approved=$this->reversalApprovals->approve($request->user(),$reversalApprovalRequest,'CASH_REMITTANCE',function(ReversalApprovalRequest $approval) use ($request){
                $row=CashRemittance::query()->where('business_id',$request->user()->business_id)->findOrFail($approval->source_id);
                return $this->service->reverse($request->user(),$row,$approval->reason);
            },$data['notes']??null);
            return response()->json(['message'=>'Cash remittance reversal approved and posted.','reversal_request'=>ReversalApprovalService::serialize($approved['request']),'remittance'=>$approved['result']]);
        } catch (DomainException $e) { return response()->json(['message'=>$e->getMessage()],422); }
    }

    public function rejectReversal(Request $request, ReversalApprovalRequest $reversalApprovalRequest, WorkflowReasonService $reasons): JsonResponse
    {
        $data=$request->validate(['review_reason_reference_id'=>['required','integer'], 'notes'=>['required','string','min:3','max:1000']]);
        $reviewReason = $reasons->resolve($request->user(), (int) $data['review_reason_reference_id'], WorkflowReasonCatalog::APPROVAL_REJECTION);
        try { $row=$this->reversalApprovals->reject($request->user(),$reversalApprovalRequest,'CASH_REMITTANCE',$data['notes'],(int)$reviewReason->id); return response()->json(['message'=>'Cash remittance reversal request rejected.','reversal_request'=>ReversalApprovalService::serialize($row)]); }
        catch (DomainException $e) { return response()->json(['message'=>$e->getMessage()],422); }
    }

}
