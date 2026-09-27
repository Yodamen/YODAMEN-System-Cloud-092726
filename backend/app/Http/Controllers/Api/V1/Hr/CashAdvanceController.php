<?php
namespace App\Http\Controllers\Api\V1\Hr;

use App\Http\Controllers\Controller;
use App\Models\EmployeeCashAdvance;
use App\Services\Hr\CashAdvanceService;
use App\Services\Foundation\SupportingDocumentService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashAdvanceController extends Controller
{
    public function index(Request $request, CashAdvanceService $service): JsonResponse { return response()->json($service->list($request->user(),$request->only(['personnel_id','status']))); }
    public function store(Request $request, CashAdvanceService $service, SupportingDocumentService $documents): JsonResponse
    {
        $data=$request->validate(['personnel_id'=>['required','integer'],'issued_date'=>['required','date_format:Y-m-d'],'original_amount'=>['required','numeric','min:0.01'],'deduction_per_payroll'=>['nullable','numeric','min:0'],'purpose'=>['nullable','string','max:255'],'notes'=>['nullable','string','max:2000'],'attachment_tokens'=>['sometimes','array','max:10'],'attachment_tokens.*'=>['required','uuid','distinct']]);
        try { $row=DB::transaction(function() use($request,$service,$documents,$data){ $row=$service->create($request->user(),$data); $documents->claimTokens($request->user(),$data['attachment_tokens']??[],'cash-advances','CASH_ADVANCE',(int)$row->id,'CASH_ADVANCE_SUPPORT'); return $row; }); } catch(DomainException $e){ return response()->json(['message'=>$e->getMessage()],422); }
        return response()->json(['message'=>'Cash advance draft created.','cash_advance'=>$service->serialize($row)],201);
    }
    public function approve(Request $request, EmployeeCashAdvance $cashAdvance, CashAdvanceService $service): JsonResponse
    {
        try { $row=$service->approve($request->user(),$cashAdvance); } catch(DomainException $e){ return response()->json(['message'=>$e->getMessage()],422); }
        return response()->json(['message'=>'Cash advance approved.','cash_advance'=>$service->serialize($row)]);
    }
    public function writeOff(Request $request, EmployeeCashAdvance $cashAdvance, CashAdvanceService $service): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        try { $row = $service->writeOff($request->user(), $cashAdvance, $data['reason']); } catch (DomainException $e) { return response()->json(['message' => $e->getMessage()], 422); }
        return response()->json(['message' => 'Cash advance balance written off.','cash_advance' => $service->serialize($row)]);
    }

    public function repayment(Request $request, EmployeeCashAdvance $cashAdvance, CashAdvanceService $service): JsonResponse
    {
        $data=$request->validate(['amount'=>['required','numeric','min:0.01'],'entry_date'=>['required','date_format:Y-m-d'],'notes'=>['nullable','string','max:2000']]);
        try { $row=$service->recordRepayment($request->user(),$cashAdvance,(float)$data['amount'],$data['entry_date'],$data['notes']??null); } catch(DomainException $e){ return response()->json(['message'=>$e->getMessage()],422); }
        return response()->json(['message'=>'Repayment recorded.','cash_advance'=>$service->serialize($row)]);
    }
}
