<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Models\AccountingDailyClosing;
use App\Models\AccountingJournalLine;
use App\Models\ChartOfAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountingReportsController extends Controller
{
    public function generalLedger(Request $request): JsonResponse
    {
        $businessId=$request->user()->business_id; [$from,$to]=$this->dates($request);
        $q=AccountingJournalLine::query()->join('accounting_journal_entries as e','e.id','=','accounting_journal_lines.journal_entry_id')
            ->leftJoin('chart_of_accounts as a','a.id','=','accounting_journal_lines.account_id')
            ->where('e.business_id',$businessId)->where('e.status','POSTED')->whereBetween('e.posting_date',[$from,$to]);
        if ($request->filled('branch_id')) $q->where('e.branch_id',$request->integer('branch_id'));
        if ($request->filled('account_id')) $q->where('accounting_journal_lines.account_id',$request->integer('account_id'));
        $rows=$q->orderByDesc('e.posting_date')->orderByDesc('e.id')->orderBy('accounting_journal_lines.line_no')->get([
            'e.posting_date','e.code as journal_code','e.reference','e.source_type','e.source_event','e.description','accounting_journal_lines.memo','accounting_journal_lines.debit','accounting_journal_lines.credit','a.id as account_id','a.code as account_code','a.name as account_name'
        ])->map(function ($row) {
            $row->posting_date = substr((string) $row->posting_date, 0, 10);
            return $row;
        });
        return response()->json(['rows'=>$rows,'options'=>$this->options($businessId),'summary'=>['debit'=>(float)$rows->sum('debit'),'credit'=>(float)$rows->sum('credit')]]);
    }

    public function trialBalance(Request $request): JsonResponse
    {
        $businessId=$request->user()->business_id; $to=$request->query('to',now()->toDateString());
        $rows=ChartOfAccount::query()->where('business_id',$businessId)->where('posting_type','POSTING')->orderBy('code')->get()->map(function($a) use($businessId,$to,$request) {
            $q=AccountingJournalLine::query()->join('accounting_journal_entries as e','e.id','=','accounting_journal_lines.journal_entry_id')->where('e.business_id',$businessId)->where('e.status','POSTED')->whereDate('e.posting_date','<=',$to)->where('accounting_journal_lines.account_id',$a->id);
            if ($request->filled('branch_id')) $q->where('e.branch_id',$request->integer('branch_id'));
            $debit=(float)(clone $q)->sum('accounting_journal_lines.debit'); $credit=(float)(clone $q)->sum('accounting_journal_lines.credit'); $net=$debit-$credit;
            return ['account_id'=>$a->id,'code'=>$a->code,'name'=>$a->name,'account_type'=>$a->account_type,'debit'=>$net>0?$net:0,'credit'=>$net<0?abs($net):0];
        })->filter(fn($r)=>$r['debit']||$r['credit'])->values();
        return response()->json(['rows'=>$rows,'summary'=>['debit'=>(float)$rows->sum('debit'),'credit'=>(float)$rows->sum('credit')],'options'=>$this->options($businessId)]);
    }

    public function cashManagement(Request $request): JsonResponse
    {
        $businessId=$request->user()->business_id; $to=$request->query('to',now()->toDateString());
        $accounts=ChartOfAccount::query()->where('business_id',$businessId)->where('active',true)->where('is_cash_account',true)->orderBy('code')->get();
        $rows=$accounts->map(function($a) use($businessId,$to,$request) {
            $q=AccountingJournalLine::query()->join('accounting_journal_entries as e','e.id','=','accounting_journal_lines.journal_entry_id')->where('e.business_id',$businessId)->where('e.status','POSTED')->whereDate('e.posting_date','<=',$to)->where('accounting_journal_lines.account_id',$a->id);
            if ($request->filled('branch_id')) $q->where('e.branch_id',$request->integer('branch_id'));
            return ['id'=>$a->id,'code'=>$a->code,'name'=>$a->name,'balance'=>(float)(clone $q)->sum('debit')-(float)(clone $q)->sum('credit')];
        });
        return response()->json(['rows'=>$rows,'summary'=>['total'=>(float)$rows->sum('balance')],'options'=>$this->options($businessId)]);
    }

    public function cashFlow(Request $request): JsonResponse
    {
        $businessId=$request->user()->business_id; [$from,$to]=$this->dates($request);
        $entries=\App\Models\AccountingJournalEntry::query()
            ->where('business_id',$businessId)->where('status','POSTED')->whereBetween('posting_date',[$from,$to])->where('source_type','!=','OPENING_BALANCE')
            ->when($request->filled('branch_id'),fn($q)=>$q->where('branch_id',$request->integer('branch_id')))
            ->with(['lines.account'])->orderByDesc('posting_date')->orderByDesc('id')->get();
        $groups=['OPERATING'=>0.0,'INVESTING'=>0.0,'FINANCING'=>0.0];
        $rows=[];
        foreach($entries as $entry){
            $cashLines=$entry->lines->filter(fn($line)=>$line->account?->is_cash_account);
            if($cashLines->isEmpty()) continue;
            $cashChange=round((float)$cashLines->sum(fn($line)=>(float)$line->debit-(float)$line->credit),2);
            if(abs($cashChange)<=0.004) continue;
            $classifications=$entry->lines->reject(fn($line)=>$line->account?->is_cash_account)
                ->map(fn($line)=>strtoupper((string)($line->account?->cash_flow_classification ?: 'OPERATING')))
                ->filter(fn($c)=>in_array($c,['OPERATING','INVESTING','FINANCING'],true))->unique()->values();
            $classification=$classifications->count()===1 ? $classifications->first() : 'OPERATING';
            $groups[$classification]=round($groups[$classification]+$cashChange,2);
            $rows[]=['date'=>$entry->posting_date?->toDateString(),'journal_code'=>$entry->code,'reference'=>$entry->reference,'description'=>$entry->description,'classification'=>$classification,'cash_change'=>$cashChange];
        }
        $opening=$this->cashBalanceBefore($businessId,$from,$to,$request);
        $net=round(array_sum($groups),2);
        return response()->json(['rows'=>$rows,'summary'=>['opening_cash'=>$opening,'operating'=>$groups['OPERATING'],'investing'=>$groups['INVESTING'],'financing'=>$groups['FINANCING'],'net_change'=>$net,'ending_cash'=>round($opening+$net,2)],'options'=>$this->options($businessId),'from'=>$from,'to'=>$to]);
    }

    public function financialStatements(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        [$from,$to] = $this->dates($request);
        $basis = strtoupper($request->query('basis','ACCRUAL'));
        $costBasis = strtoupper($request->query('cost_basis','SYSTEM'));
        $year = max(2000, min(2100, (int) $request->query('year', now()->year)));
        $accounts = ChartOfAccount::query()->where('business_id',$businessId)->where('posting_type','POSTING')->orderBy('code')->get();

        $periodAmounts = $this->statementAmounts($accounts, $businessId, $from, $to, $request, $basis, $costBasis);
        $section = fn($type) => $accounts->where('account_type',$type)->map(fn($a) => [
            'code'=>$a->code,'name'=>$a->name,'financial_statement_group'=>$a->financial_statement_group,
            'amount'=>round($periodAmounts[$a->id] ?? 0,2),
        ])->filter(fn($r)=>abs($r['amount'])>0.004)->values();
        $income=$section('INCOME'); $cos=$section('COST_OF_SALES'); $opex=$section('OPERATING_EXPENSE');
        $revenue=(float)$income->sum('amount'); $cost=(float)$cos->sum('amount'); $expenses=(float)$opex->sum('amount');
        $assets=$this->balancesAsOf($accounts->where('account_type','ASSET'),$businessId,$to,$request);
        $liabilities=$this->balancesAsOf($accounts->where('account_type','LIABILITY'),$businessId,$to,$request);
        $equity=$this->balancesAsOf($accounts->where('account_type','EQUITY'),$businessId,$to,$request);

        $yearFrom = sprintf('%04d-01-01', $year);
        $yearTo = sprintf('%04d-12-31', $year);
        $yearLines = AccountingJournalLine::query()
            ->join('accounting_journal_entries as e','e.id','=','accounting_journal_lines.journal_entry_id')
            ->where('e.business_id',$businessId)->where('e.status','POSTED')->whereBetween('e.posting_date',[$yearFrom,$yearTo])
            ->when($request->filled('branch_id'),fn($q)=>$q->where('e.branch_id',$request->integer('branch_id')))
            ->when($basis==='CASH',fn($q)=>$q->whereExists(function($sub){ $sub->selectRaw('1')->from('accounting_journal_lines as cl')->join('chart_of_accounts as ca','ca.id','=','cl.account_id')->whereColumn('cl.journal_entry_id','e.id')->where('ca.is_cash_account',true); }))
            ->get(['accounting_journal_lines.account_id','accounting_journal_lines.debit','accounting_journal_lines.credit','e.posting_date']);

        $monthly = [];
        foreach ($yearLines as $line) {
            $account = $accounts->firstWhere('id', (int) $line->account_id);
            if (! $account) continue;
            if ($costBasis==='SYSTEM' && in_array($account->system_key,['INVENTORY_COUNT_VARIANCE','INVENTORY_COUNT_GAIN'],true)) continue;
            $month = (int) substr((string) $line->posting_date, 5, 2);
            $value = match ($account->account_type) {
                'INCOME' => (float)$line->credit - (float)$line->debit,
                'COST_OF_SALES','OPERATING_EXPENSE' => (float)$line->debit - (float)$line->credit,
                default => 0,
            };
            $monthly[$account->id][$month] = round(($monthly[$account->id][$month] ?? 0) + $value, 2);
        }

        $groupLabels = DB::table('reference_values as rv')->join('reference_groups as rg','rg.id','=','rv.reference_group_id')
            ->where('rg.business_id',$businessId)->where('rg.code','FINANCIAL_STATEMENT_GROUP')->where('rv.active',true)
            ->orderBy('rv.sort_order')->pluck('rv.display_value','rv.code');
        $statementRows = [];
        foreach ($accounts->whereIn('account_type',['INCOME','COST_OF_SALES','OPERATING_EXPENSE']) as $account) {
            $months = [];
            for ($m=1;$m<=12;$m++) $months[] = round($monthly[$account->id][$m] ?? 0,2);
            if (array_sum(array_map('abs',$months)) <= 0.004) continue;
            $groupCode = $account->financial_statement_group ?: ($account->account_group ?: $account->account_type);
            $statementRows[] = [
                'code'=>$account->code,'name'=>$account->name,'section'=>$account->account_type,
                'group_code'=>$groupCode,'group_name'=>$groupLabels[$groupCode] ?? str($groupCode)->replace('_',' ')->title()->toString(),
                'months'=>$months,'total'=>round(array_sum($months),2),
            ];
        }
        $summaries = ['gross_revenue'=>array_fill(0,12,0.0),'discounts_returns'=>array_fill(0,12,0.0),'net_revenue'=>array_fill(0,12,0.0),'cost_of_sales'=>array_fill(0,12,0.0),'gross_profit'=>array_fill(0,12,0.0),'operating_expenses'=>array_fill(0,12,0.0),'net_income'=>array_fill(0,12,0.0)];
        foreach ($statementRows as $row) {
            foreach ($row['months'] as $i=>$value) {
                if ($row['section']==='INCOME') {
                    if ($row['group_code']==='DISCOUNTS_RETURNS') {
                        $summaries['discounts_returns'][$i] += abs($value);
                    } else {
                        $summaries['gross_revenue'][$i] += $value;
                    }
                    $summaries['net_revenue'][$i] += $value;
                } elseif ($row['section']==='COST_OF_SALES') $summaries['cost_of_sales'][$i] += $value;
                elseif ($row['section']==='OPERATING_EXPENSE') $summaries['operating_expenses'][$i] += $value;
            }
        }
        for ($i=0;$i<12;$i++) {
            $summaries['gross_profit'][$i] = round($summaries['net_revenue'][$i]-$summaries['cost_of_sales'][$i],2);
            $summaries['net_income'][$i] = round($summaries['gross_profit'][$i]-$summaries['operating_expenses'][$i],2);
            foreach ($summaries as $key=>$values) $summaries[$key][$i] = round($summaries[$key][$i],2);
        }
        $summaryTotals=[]; foreach($summaries as $key=>$values) $summaryTotals[$key]=round(array_sum($values),2);

        return response()->json([
            'income_statement'=>['income'=>$income,'cost_of_sales'=>$cos,'operating_expenses'=>$opex,'revenue'=>$revenue,'cost_of_sales_total'=>$cost,'gross_profit'=>$revenue-$cost,'operating_expenses_total'=>$expenses,'net_income'=>$revenue-$cost-$expenses],
            'income_statement_yearly'=>['year'=>$year,'rows'=>$statementRows,'summary'=>$summaries,'totals'=>$summaryTotals],
            'balance_sheet'=>['assets'=>$assets,'liabilities'=>$liabilities,'equity'=>$equity,'asset_total'=>(float)$assets->sum('amount'),'liability_total'=>(float)$liabilities->sum('amount'),'equity_total'=>(float)$equity->sum('amount')],
            'basis'=>$basis,'cost_basis'=>$costBasis,'from'=>$from,'to'=>$to,'year'=>$year,'options'=>$this->options($businessId),
        ]);
    }

    private function statementAmounts($accounts, int $businessId, string $from, string $to, Request $request, string $basis, string $costBasis): array
    {
        $amounts=[];
        foreach ($accounts as $a) {
            $q=AccountingJournalLine::query()->join('accounting_journal_entries as e','e.id','=','accounting_journal_lines.journal_entry_id')->where('e.business_id',$businessId)->where('e.status','POSTED')->whereBetween('e.posting_date',[$from,$to])->where('accounting_journal_lines.account_id',$a->id);
            if ($request->filled('branch_id')) $q->where('e.branch_id',$request->integer('branch_id'));
            if ($basis==='CASH') $q->whereExists(function($sub){ $sub->selectRaw('1')->from('accounting_journal_lines as cl')->join('chart_of_accounts as ca','ca.id','=','cl.account_id')->whereColumn('cl.journal_entry_id','e.id')->where('ca.is_cash_account',true); });
            if ($costBasis==='SYSTEM' && in_array($a->system_key,['INVENTORY_COUNT_VARIANCE','INVENTORY_COUNT_GAIN'],true)) { $amounts[$a->id]=0; continue; }
            $debit=(float)(clone $q)->sum('accounting_journal_lines.debit'); $credit=(float)(clone $q)->sum('accounting_journal_lines.credit');
            $amounts[$a->id] = match ($a->account_type) { 'INCOME'=>$credit-$debit, 'COST_OF_SALES','OPERATING_EXPENSE'=>$debit-$credit, default=>$a->normal_balance==='CREDIT'?$credit-$debit:$debit-$credit };
        }
        return $amounts;
    }

    public function dailyClosings(Request $request): JsonResponse
    {
        $businessId=$request->user()->business_id; [$from,$to]=$this->dates($request);
        $rows=AccountingDailyClosing::query()->where('business_id',$businessId)->whereBetween('closing_date',[$from,$to])->with(['branch','closedBy'])->orderByDesc('closing_date')->get()->map(fn($r)=>['id'=>$r->id,'closing_date'=>$r->closing_date?->toDateString(),'status'=>$r->status,'notes'=>$r->notes,'branch'=>$r->branch?->only(['id','name']),'closed_by'=>$r->closedBy?->display_name,'closed_at'=>$r->closed_at?->toIso8601String(),'reopen_reason'=>$r->reopen_reason]);
        return response()->json(['rows'=>$rows,'options'=>$this->options($businessId)]);
    }

    public function closeDay(Request $request): JsonResponse
    {
        $businessId=$request->user()->business_id; $data=$request->validate(['branch_id'=>['required','integer','exists:branches,id'],'closing_date'=>['required','date_format:Y-m-d'],'notes'=>['nullable','string','max:1000']]);
        abort_unless(DB::table('branches')->where('business_id',$businessId)->where('id',$data['branch_id'])->exists(),404);
        $row=AccountingDailyClosing::query()->updateOrCreate(['business_id'=>$businessId,'branch_id'=>$data['branch_id'],'closing_date'=>$data['closing_date']],['status'=>'CLOSED','notes'=>$data['notes']??null,'closed_at'=>now(),'closed_by'=>$request->user()->id,'reopened_at'=>null,'reopened_by'=>null,'reopen_reason'=>null]);
        return response()->json(['closing'=>$row]);
    }

    public function reopenDay(Request $request, AccountingDailyClosing $accountingDailyClosing): JsonResponse
    {
        abort_unless($accountingDailyClosing->business_id===$request->user()->business_id,404); $data=$request->validate(['reason'=>['required','string','min:5','max:1000']]);
        $accountingDailyClosing->update(['status'=>'REOPENED','reopened_at'=>now(),'reopened_by'=>$request->user()->id,'reopen_reason'=>$data['reason']]);
        return response()->json(['closing'=>$accountingDailyClosing->fresh()]);
    }

    private function balancesAsOf($accounts,int $businessId,string $to,Request $request) {
        return $accounts->map(function($a) use($businessId,$to,$request){ $q=AccountingJournalLine::query()->join('accounting_journal_entries as e','e.id','=','accounting_journal_lines.journal_entry_id')->where('e.business_id',$businessId)->where('e.status','POSTED')->whereDate('e.posting_date','<=',$to)->where('accounting_journal_lines.account_id',$a->id); if($request->filled('branch_id'))$q->where('e.branch_id',$request->integer('branch_id')); $d=(float)(clone $q)->sum('accounting_journal_lines.debit');$c=(float)(clone $q)->sum('accounting_journal_lines.credit');$v=$a->normal_balance==='CREDIT'?$c-$d:$d-$c; return ['code'=>$a->code,'name'=>$a->name,'amount'=>round($v,2)]; })->filter(fn($r)=>abs($r['amount'])>0.004)->values();
    }
    private function cashBalanceBefore(int $businessId,string $from,string $to,Request $request): float {
        $q=AccountingJournalLine::query()->join('accounting_journal_entries as e','e.id','=','accounting_journal_lines.journal_entry_id')->join('chart_of_accounts as a','a.id','=','accounting_journal_lines.account_id')->where('e.business_id',$businessId)->where('e.status','POSTED')->where('a.is_cash_account',true)
            ->where(function($dateQuery) use($from,$to){
                $dateQuery->where(function($historical) use($from){
                    $historical->where('e.source_type','!=','OPENING_BALANCE')->whereDate('e.posting_date','<',$from);
                })->orWhere(function($openingQuery) use($to){
                    $openingQuery->where('e.source_type','OPENING_BALANCE')->whereDate('e.posting_date','<=',$to);
                });
            });
        if($request->filled('branch_id'))$q->where('e.branch_id',$request->integer('branch_id'));
        return round((float)(clone $q)->sum('accounting_journal_lines.debit')-(float)(clone $q)->sum('accounting_journal_lines.credit'),2);
    }
    private function dates(Request $request): array { return [$request->query('from',now()->startOfMonth()->toDateString()),$request->query('to',now()->toDateString())]; }
    private function options(int $businessId): array { return ['branches'=>DB::table('branches')->where('business_id',$businessId)->where('status','ACTIVE')->orderBy('name')->get(['id','name']),'accounts'=>ChartOfAccount::query()->where('business_id',$businessId)->where('active',true)->where('posting_type','POSTING')->orderBy('code')->get(['id','code','name'])]; }
}
