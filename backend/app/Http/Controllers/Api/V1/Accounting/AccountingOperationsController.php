<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Models\BankReconciliation;
use App\Models\ChartOfAccount;
use App\Models\Payment;
use App\Models\OpeningCustomerReceivable;
use App\Models\Personnel;
use App\Models\ProfitSharingRun;
use App\Models\Sale;
use App\Services\Accounting\BankReconciliationService;
use App\Services\Accounting\ProfitSharingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountingOperationsController extends Controller
{
    public function accountsReceivable(Request $request): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;
        $asOf = $request->query('as_of', now()->toDateString());

        $saleRows = Sale::query()
            ->where('business_id', $businessId)
            ->whereNotNull('financial_finalized_at')
            ->where('status', '<>', 'REVERSED')
            ->where('outstanding_balance', '>', 0.004)
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->with(['customer','booking','branch'])
            ->orderByDesc('financial_finalized_at')->orderByDesc('id')->get()
            ->map(function (Sale $sale) use ($asOf) {
                $age = max(0, \Carbon\CarbonImmutable::parse($sale->financial_finalized_at)->startOfDay()->diffInDays(\Carbon\CarbonImmutable::parse($asOf)->startOfDay(), false));
                $bucket = $age <= 0 ? 'CURRENT' : ($age <= 30 ? '1-30' : ($age <= 60 ? '31-60' : ($age <= 90 ? '61-90' : 'OVER_90')));
                return [
                    'id' => 'sale-'.$sale->id,
                    'source_type' => 'SALE',
                    'source_id' => $sale->id,
                    'sale_code' => $sale->code,
                    'booking_code' => $sale->booking?->code,
                    'customer' => $sale->customer?->full_name ?: 'Walk-In / Unlinked',
                    'branch' => $sale->branch?->name,
                    'finalized_at' => $sale->financial_finalized_at?->toIso8601String(),
                    'net_amount' => (float) $sale->effective_net_amount,
                    'applied_amount' => (float) $sale->applied_amount,
                    'outstanding_balance' => (float) $sale->outstanding_balance,
                    'age_days' => $age,
                    'aging_bucket' => $bucket,
                    'status' => $sale->status,
                ];
            });

        $openingRows = OpeningCustomerReceivable::query()
            ->where('business_id', $businessId)
            ->where('status', 'OPEN')
            ->where('remaining_balance', '>', 0.004)
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->with(['customer','branch','packageEnrollment'])
            ->orderByDesc('cutover_date')->orderByDesc('id')->get()
            ->map(function (OpeningCustomerReceivable $row) use ($asOf) {
                $age = max(0, \Carbon\CarbonImmutable::parse($row->cutover_date)->startOfDay()->diffInDays(\Carbon\CarbonImmutable::parse($asOf)->startOfDay(), false));
                $bucket = $age <= 0 ? 'CURRENT' : ($age <= 30 ? '1-30' : ($age <= 60 ? '31-60' : ($age <= 90 ? '61-90' : 'OVER_90')));
                $applied = max(0, round((float) $row->original_amount - (float) $row->remaining_balance, 2));
                $label = $row->packageEnrollment?->service_name_snapshot ?: ($row->reference ?: 'Opening Customer Balance');

                return [
                    'id' => 'opening-'.$row->id,
                    'source_type' => 'OPENING_RECEIVABLE',
                    'source_id' => $row->id,
                    'sale_code' => $row->code,
                    'booking_code' => $row->packageEnrollment ? 'Opening Package · '.$row->packageEnrollment->code : $label,
                    'customer' => $row->customer?->full_name ?: 'Unlinked Customer',
                    'branch' => $row->branch?->name,
                    'finalized_at' => $row->cutover_date?->startOfDay()->toIso8601String(),
                    'net_amount' => (float) $row->original_amount,
                    'applied_amount' => $applied,
                    'outstanding_balance' => (float) $row->remaining_balance,
                    'age_days' => $age,
                    'aging_bucket' => $bucket,
                    'status' => 'OPENING_BALANCE',
                ];
            });

        $rows = $saleRows->concat($openingRows)
            ->sortByDesc(fn (array $row) => ($row['finalized_at'] ?? '').'|'.str_pad((string) ($row['source_id'] ?? 0), 12, '0', STR_PAD_LEFT))
            ->values();

        $summary = [
            'total' => round((float) $rows->sum('outstanding_balance'), 2),
            'current' => round((float) $rows->where('aging_bucket','CURRENT')->sum('outstanding_balance'), 2),
            '1_30' => round((float) $rows->where('aging_bucket','1-30')->sum('outstanding_balance'), 2),
            '31_60' => round((float) $rows->where('aging_bucket','31-60')->sum('outstanding_balance'), 2),
            '61_90' => round((float) $rows->where('aging_bucket','61-90')->sum('outstanding_balance'), 2),
            'over_90' => round((float) $rows->where('aging_bucket','OVER_90')->sum('outstanding_balance'), 2),
        ];
        return response()->json(['rows'=>$rows,'summary'=>$summary,'options'=>$this->options($businessId),'as_of'=>$asOf]);
    }

    public function collections(Request $request): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;
        $from = $request->query('from', now()->startOfMonth()->toDateString());
        $to = $request->query('to', now()->toDateString());
        $payments = Payment::query()
            ->where('business_id',$businessId)
            ->where('payment_type', '!=', 'TIP')
            ->whereBetween(DB::raw('DATE(payment_date)'), [$from,$to])
            ->when($request->filled('branch_id'), function ($q) use ($request) {
                $branchId = $request->integer('branch_id');
                $q->where(function ($w) use ($branchId) {
                    $w->whereHas('sale', fn ($s) => $s->where('branch_id',$branchId))
                        ->orWhereHas('booking', fn ($b) => $b->where('branch_id',$branchId));
                });
            })
            ->with(['customer','sale.branch','booking.branch','applications'])
            ->orderByDesc('payment_date')->orderByDesc('id')->get();

        $rows = $payments->map(function (Payment $payment) {
            $activeApplied = round((float) $payment->applications->where('status','ACTIVE')->sum('amount'), 2);
            $reversedApplied = round((float) $payment->applications->where('status','REVERSED')->sum('amount'), 2);
            $amount = round((float) $payment->amount, 2);
            return [
                'id'=>$payment->id,'code'=>$payment->code,
                'date'=>$payment->payment_date?->toIso8601String(),
                'customer'=>$payment->customer?->full_name ?: 'Walk-In / Unlinked',
                'sale_code'=>$payment->sale?->code,
                'booking_code'=>$payment->booking?->code,
                'branch'=>$payment->sale?->branch?->name ?: $payment->booking?->branch?->name,
                'type'=>$payment->payment_type,
                'method'=>$payment->payment_method_snapshot,
                'reference'=>$payment->reference_no,
                'amount'=>$amount,
                'applied'=>$activeApplied,
                'unapplied'=>max(0, round($amount-$activeApplied,2)),
                'reversed_applied'=>$reversedApplied,
                'status'=>$payment->status,
            ];
        });
        $received = $rows->where('status','RECEIVED');
        return response()->json([
            'rows'=>$rows->values(),
            'summary'=>[
                'cash_received'=>round((float)$received->sum('amount'),2),
                'applied'=>round((float)$received->sum('applied'),2),
                'unapplied'=>round((float)$received->sum('unapplied'),2),
                'reversed'=>round((float)$rows->where('status','REVERSED')->sum('amount'),2),
            ],
            'options'=>$this->options($businessId),'from'=>$from,'to'=>$to,
        ]);
    }

    public function bankReconciliations(Request $request): JsonResponse
    {
        $businessId=(int)$request->user()->business_id;
        $rows=BankReconciliation::query()->where('business_id',$businessId)
            ->when($request->filled('branch_id'),fn($q)=>$q->where('branch_id',$request->integer('branch_id')))
            ->with(['branch','bankAccount','items.adjustmentAccount','creator','reconciler'])
            ->orderByDesc('statement_date')->orderByDesc('id')->get();
        return response()->json(['rows'=>$rows,'options'=>$this->reconciliationOptions($businessId)]);
    }

    public function saveBankReconciliation(Request $request, BankReconciliationService $service, ?BankReconciliation $bankReconciliation = null): JsonResponse
    {
        $data=$request->validate([
            'branch_id'=>['nullable','integer'], 'bank_account_id'=>['required','integer'], 'statement_date'=>['required','date'],
            'statement_ending_balance'=>['required','numeric'], 'notes'=>['nullable','string','max:2000'],
            'items'=>['array'], 'items.*.item_type'=>['required','string','max:50'], 'items.*.effect_on'=>['required','in:BANK,BOOK'],
            'items.*.description'=>['required','string','max:255'], 'items.*.reference'=>['nullable','string','max:120'],
            'items.*.amount'=>['required','numeric'], 'items.*.adjustment_account_id'=>['nullable','integer'],
        ]);
        $row=$service->saveDraft($request->user(),$data,$bankReconciliation);
        return response()->json(['reconciliation'=>$row],$bankReconciliation?200:201);
    }

    public function reconcileBank(Request $request, BankReconciliation $bankReconciliation, BankReconciliationService $service): JsonResponse
    {
        return response()->json(['reconciliation'=>$service->reconcile($request->user(),$bankReconciliation)]);
    }

    public function profitSharingRuns(Request $request): JsonResponse
    {
        $businessId=(int)$request->user()->business_id;
        $rows=ProfitSharingRun::query()->where('business_id',$businessId)->with(['branch','lines.personnel','paymentAccount'])->orderByDesc('period_end')->orderByDesc('id')->get();
        return response()->json(['rows'=>$rows,'options'=>$this->profitOptions($businessId)]);
    }

    public function createProfitSharing(Request $request, ProfitSharingService $service): JsonResponse
    {
        $data=$request->validate([
            'branch_id'=>['nullable','integer'],'period_start'=>['required','date'],'period_end'=>['required','date','after_or_equal:period_start'],
            'basis'=>['required','in:ACCRUAL,CASH'],'cost_basis'=>['required','in:SYSTEM,AUDIT_ADJUSTED'],
            'distributable_profit'=>['nullable','numeric','min:0'],'notes'=>['nullable','string','max:2000'],
        ]);
        return response()->json(['run'=>$service->createDraft($request->user(),$data)],201);
    }

    public function recalculateProfitSharing(Request $request, ProfitSharingRun $profitSharingRun, ProfitSharingService $service): JsonResponse
    {
        $data=$request->validate(['distributable_profit'=>['nullable','numeric','min:0']]);
        return response()->json(['run'=>$service->recalculate($request->user(),$profitSharingRun,$data['distributable_profit']??null)]);
    }

    public function approveProfitSharing(Request $request, ProfitSharingRun $profitSharingRun, ProfitSharingService $service): JsonResponse
    {
        return response()->json(['run'=>$service->approve($request->user(),$profitSharingRun)]);
    }

    public function payProfitSharing(Request $request, ProfitSharingRun $profitSharingRun, ProfitSharingService $service): JsonResponse
    {
        $data=$request->validate(['payment_account_id'=>['required','integer']]);
        return response()->json(['run'=>$service->markPaid($request->user(),$profitSharingRun,(int)$data['payment_account_id'])]);
    }

    public function myProfit(Request $request): JsonResponse
    {
        $businessId=(int)$request->user()->business_id;
        $personnel=Personnel::query()->where('business_id',$businessId)->where('linked_user_id',$request->user()->id)->first();
        if (!$personnel) return response()->json(['rows'=>[],'summary'=>['approved'=>0,'paid'=>0],'personnel'=>null]);
        $rows=DB::table('profit_sharing_lines as l')->join('profit_sharing_runs as r','r.id','=','l.profit_sharing_run_id')
            ->where('r.business_id',$businessId)->where('l.personnel_id',$personnel->id)->whereIn('r.status',['APPROVED','PAID'])
            ->orderByDesc('r.period_end')->get(['r.code','r.period_start','r.period_end','r.status','l.percentage_snapshot','l.share_amount','r.paid_at']);
        return response()->json(['rows'=>$rows,'summary'=>['approved'=>(float)$rows->where('status','APPROVED')->sum('share_amount'),'paid'=>(float)$rows->where('status','PAID')->sum('share_amount')],'personnel'=>['code'=>$personnel->code,'name'=>$personnel->full_name]]);
    }

    private function options(int $businessId): array
    {
        return ['branches'=>DB::table('branches')->where('business_id',$businessId)->where('status','ACTIVE')->orderBy('name')->get(['id','name'])];
    }

    private function reconciliationOptions(int $businessId): array
    {
        return [
            'branches'=>$this->options($businessId)['branches'],
            'bank_accounts'=>ChartOfAccount::query()->where('business_id',$businessId)->where('active',true)->where('is_cash_account',true)->whereIn('system_key',['CASH_IN_BANK','POS_CLEARING'])->orderBy('code')->get(['id','code','name']),
            'adjustment_accounts'=>ChartOfAccount::query()->where('business_id',$businessId)->where('active',true)->where('posting_type','POSTING')->orderBy('code')->get(['id','code','name']),
        ];
    }

    private function profitOptions(int $businessId): array
    {
        return [
            'branches'=>$this->options($businessId)['branches'],
            'cash_accounts'=>ChartOfAccount::query()->where('business_id',$businessId)->where('active',true)->where('is_cash_account',true)->orderBy('code')->get(['id','code','name']),
        ];
    }
}
