<?php
namespace App\Services\Hr;

use App\Models\Attendance;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\CommissionEarning;
use App\Models\DailyCompensation;
use App\Models\EmployeeCashAdvance;
use App\Models\EmployeeCashAdvanceLedger;
use App\Models\PayrollAdjustment;
use App\Models\PayrollCashAdvanceAllocation;
use App\Models\PayrollCashAdvanceWaiver;
use App\Models\PayrollLine;
use App\Models\PayrollPaymentSplit;
use App\Models\PayrollRecovery;
use App\Models\ReversalApprovalRequest;
use App\Models\PayrollRecoveryAllocation;
use App\Models\PayrollRun;
use App\Models\HrLeaveRequest;
use App\Models\Personnel;
use App\Models\TransactionActionRequest;
use App\Models\User;
use App\Services\Commissions\CommissionEarningService;
use App\Services\Foundation\BusinessClock;
use App\Services\Foundation\CodeSequenceService;
use App\Services\Security\AuditService;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PayrollService
{
    public function __construct(
        private readonly CodeSequenceService $codes,
        private readonly BusinessClock $clock,
        private readonly AuditService $audit,
        private readonly DailyCompensationService $dailyCompensation,
        private readonly PayrollRecoveryService $payrollRecoveries,
        private readonly CommissionEarningService $commissionEarnings,
    ) {}

    public function list(User $actor): array
    {
        $rows=PayrollRun::query()->where('business_id',$actor->business_id)->withCount('lines')->with(['branch','paymentAccount','paymentSplits.fundingAccount'])->orderByDesc('period_end')->orderByDesc('id')->get();
        return [
            'runs'=>$rows->map(fn($r)=>$this->serializeRun($r))->values(),
            'personnel'=>Personnel::query()->where('business_id',$actor->business_id)->where('status','ACTIVE')->orderBy('last_name')->orderBy('first_name')->get()->map(fn($p)=>['id'=>$p->id,'code'=>$p->code,'name'=>$p->full_name])->values(),
            'branches'=>Branch::query()->where('business_id',$actor->business_id)->where('status','ACTIVE')->orderBy('name')->get()->map(fn($b)=>['id'=>$b->id,'code'=>$b->code,'name'=>$b->name])->values(),
            'funding_sources'=>ChartOfAccount::query()->where('business_id',$actor->business_id)->where('active',true)->where('posting_type','POSTING')->where('is_funding_source',true)->orderBy('code')->get()->map(fn($a)=>['id'=>$a->id,'code'=>$a->code,'name'=>$a->name])->values(),
            'adjustments'=>$this->serializeAdjustments($actor),
            'recoveries'=>PayrollRecovery::query()->where('business_id',$actor->business_id)->whereIn('status',['OPEN','PARTIAL'])->where('remaining_amount','>',0.009)->with(['personnel','originalPayrollRun','sourceAction'])->orderBy('created_at')->orderBy('id')->get()->map(fn($r)=>[
                'id'=>$r->id,'code'=>$r->code,'recovery_type'=>$r->recovery_type,
                'personnel'=>['id'=>$r->personnel_id,'name'=>$r->personnel?->full_name],
                'source_label'=>$r->source_label,'reason'=>$r->reason,'origin_work_date'=>$r->origin_work_date?->toDateString(),
                'original_payroll_code'=>$r->originalPayrollRun?->code,'source_action_code'=>$r->sourceAction?->code,
                'recovery_amount'=>(float)$r->recovery_amount,'applied_amount'=>(float)$r->applied_amount,'remaining_amount'=>(float)$r->remaining_amount,'status'=>$r->status,
            ])->values(),
        ];
    }

    public function show(User $actor, PayrollRun $run): array
    {
        $this->guardBusiness($actor,$run);
        $run->load(['branch','paymentAccount','paymentSplits.fundingAccount','lines.personnel','lines.cashAdvanceAllocations.cashAdvance','lines.recoveryAllocations.recovery.originalPayrollRun','lines.recoveryAllocations.recovery.sourceAction','cashAdvanceWaivers.cashAdvance']);
        $payload=$this->serializeRun($run,true);
        $payload['approval_exceptions']=$run->status==='DRAFT' ? $this->approvalExceptions($actor,$run) : [];
        return ['run'=>$payload];
    }

    public function create(User $actor, array $data): PayrollRun
    {
        $today = $this->clock->today();
        if ($data['period_start'] > $today || $data['period_end'] > $today) throw new DomainException('Payroll period cannot include future dates.');
        if ($data['period_end'] < $data['period_start']) throw new DomainException('Payroll period end must be on or after the start date.');
        $overlap=PayrollRun::query()->where('business_id',$actor->business_id)->whereIn('status',['DRAFT','APPROVED','PAID'])
            ->whereDate('period_start','<=',$data['period_end'])->whereDate('period_end','>=',$data['period_start'])
            ->where(function($query) use($data){
                $branchId=$data['branch_id']??null;
                if($branchId){$query->whereNull('branch_id')->orWhere('branch_id',$branchId);}
            })->exists();
        if ($overlap) throw new DomainException('An active payroll run already overlaps this payroll period for the selected branch scope.');
        $now=$this->clock->now();
        $run=PayrollRun::query()->create([
            'business_id'=>$actor->business_id,'code'=>$this->codes->next('PAYROLL_RUN:'.$now->format('my'),'PAY-'.$now->format('my').'-',4,$actor->business_id),
            'period_start'=>$data['period_start'],'period_end'=>$data['period_end'],'pay_date'=>$data['pay_date'],'branch_id'=>$data['branch_id']??null,
            'status'=>'DRAFT','notes'=>$data['notes']??null,'created_by'=>$actor->id,
        ]);
        return $this->recalculate($actor,$run);
    }

    public function recalculate(User $actor, PayrollRun $run): PayrollRun
    {
        $this->guardBusiness($actor,$run);
        if ($run->status !== 'DRAFT') throw new DomainException('Only Draft payroll can be recalculated.');

        DB::transaction(function() use($run){
            $lockedRun=PayrollRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($lockedRun->status !== 'DRAFT') throw new DomainException('Only Draft payroll can be recalculated.');
            $this->releaseDraftSources($lockedRun);
            $this->payrollRecoveries->releaseRunReservations($lockedRun);
            PayrollCashAdvanceAllocation::query()->where('payroll_run_id',$lockedRun->id)->delete();
            PayrollLine::query()->where('payroll_run_id',$lockedRun->id)->delete();
        });

        $run->refresh();
        $this->dailyCompensation->rebuildRange($actor,$run->period_start->toDateString(),$run->period_end->toDateString(),null);

        return DB::transaction(function() use($actor,$run){
            $run=PayrollRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($run->status !== 'DRAFT') throw new DomainException('Only Draft payroll can be recalculated.');

            $daily=DailyCompensation::query()->where('business_id',$run->business_id)->where('status','OPEN')
                ->whereDate('work_date','>=',$run->period_start)->whereDate('work_date','<=',$run->period_end)
                ->when($run->branch_id,fn($query)=>$query->whereHas('personnel',fn($personnel)=>$personnel->where('branch_id',$run->branch_id)))
                ->with('personnel.position')->get();
            $adjustments=PayrollAdjustment::query()->where('business_id',$run->business_id)->where('status','OPEN')
                ->whereDate('effective_date','>=',$run->period_start)->whereDate('effective_date','<=',$run->period_end)
                ->when($run->branch_id,fn($query)=>$query->whereHas('personnel',fn($personnel)=>$personnel->where('branch_id',$run->branch_id)))->get();
            $recoveries=$this->payrollRecoveries->eligibleQuery($run)->get();
            $personnelIds=$daily->pluck('personnel_id')->merge($adjustments->pluck('personnel_id'))->merge($recoveries->pluck('personnel_id'))->unique()->values();
            $grossTotal=$adjustTotal=$advanceTotal=$recoveryTotal=$statutoryEmployeeTotal=$statutoryEmployerTotal=$netTotal=0.0;

            foreach($personnelIds as $personnelId){
                $personnel=Personnel::query()->with('position')->find($personnelId); if(!$personnel) continue;
                $days=$daily->where('personnel_id',$personnelId);
                $personAdjust=$adjustments->where('personnel_id',$personnelId);
                $gross=round((float)$days->sum('gross_daily_projection'),2);
                $adjust=round((float)$personAdjust->sum('amount'),2);
                if ($gross+$adjust < -0.005) throw new DomainException('Payroll adjustments for '.$personnel->full_name.' exceed available gross compensation.');
                $line=PayrollLine::query()->create([
                    'payroll_run_id'=>$run->id,'business_id'=>$run->business_id,'personnel_id'=>$personnel->id,
                    'personnel_code_snapshot'=>$personnel->code,'personnel_name_snapshot'=>$personnel->full_name,'position_snapshot'=>$personnel->position?->display_value,
                    'hourly_basic_pay'=>round((float)$days->sum('hourly_basic_pay'),2),'ot_pay'=>round((float)$days->sum('ot_pay'),2),
                    'commission_amount'=>round((float)$days->sum('confirmed_commission'),2),'guaranteed_top_up'=>round((float)$days->sum('guaranteed_top_up'),2),
                    'gross_compensation'=>$gross,'adjustments_total'=>$adjust,'cash_advance_deductions'=>0,'payroll_recovery_deductions'=>0,
                    'statutory_programs_snapshot'=>[
                        'sss'=>(bool)$personnel->sss_enabled,'philhealth'=>(bool)$personnel->philhealth_enabled,'pagibig'=>(bool)$personnel->pagibig_enabled,
                    ],
                    'sss_employee'=>0,'sss_employer'=>0,'philhealth_employee'=>0,'philhealth_employer'=>0,'pagibig_employee'=>0,'pagibig_employer'=>0,
                    'statutory_employee_deductions'=>0,'statutory_employer_contributions'=>0,
                    'net_pay'=>round($gross+$adjust,2),'status'=>'DRAFT',
                ]);
                $days->each(fn($row)=>$row->update(['status'=>'IN_PAYROLL_DRAFT','payroll_run_id'=>$run->id,'payroll_line_id'=>$line->id]));
                $personAdjust->each(fn($row)=>$row->update(['status'=>'IN_PAYROLL_DRAFT','payroll_run_id'=>$run->id,'payroll_line_id'=>$line->id]));

                CommissionEarning::query()->where('business_id',$run->business_id)->where('personnel_id',$personnel->id)->where('status','OPEN')
                    ->whereDate('work_date','>=',$run->period_start)->whereDate('work_date','<=',$run->period_end)
                    ->update(['status'=>'IN_PAYROLL_DRAFT','payroll_run_id'=>$run->id,'payroll_line_id'=>$line->id]);

                $capacity=max(0,round($gross+$adjust,2)); $deductions=0.0;
                $activeWaivers=PayrollCashAdvanceWaiver::query()->where('payroll_run_id',$run->id)->where('status','WAIVED')->get()->keyBy('cash_advance_id');
                $advances=EmployeeCashAdvance::query()->where('business_id',$run->business_id)->where('personnel_id',$personnel->id)->where('status','ACTIVE')->where('deduction_per_payroll','>',0)->with('ledger')->orderBy('issued_date')->orderBy('id')->get();
                foreach($advances as $advance){
                    if($capacity-$deductions<=0.005) break;
                    $balance=max(0,round((float)$advance->ledger->sum('amount'),2));
                    $amount=min((float)$advance->deduction_per_payroll,$balance,$capacity-$deductions);
                    $amount=round(max(0,$amount),2); if($amount<=0) continue;
                    $waiver=$activeWaivers->get($advance->id);
                    if($waiver){
                        $waiver->update(['payroll_line_id'=>$line->id,'scheduled_amount'=>$amount,'waived_amount'=>$amount]);
                        continue;
                    }
                    PayrollCashAdvanceAllocation::query()->create(['payroll_run_id'=>$run->id,'payroll_line_id'=>$line->id,'cash_advance_id'=>$advance->id,'amount'=>$amount,'status'=>'RESERVED']);
                    $deductions+= $amount;
                }
                $statutoryEmployee=round((float)$line->statutory_employee_deductions,2);
                $statutoryEmployer=round((float)$line->statutory_employer_contributions,2);
                $recoveryCapacity=max(0,round($gross+$adjust-$deductions-$statutoryEmployee,2));
                $recoveryDeduction=$this->payrollRecoveries->reserveForLine($actor,$run,$line,$recoveryCapacity);
                $line->update([
                    'cash_advance_deductions'=>round($deductions,2),
                    'payroll_recovery_deductions'=>round($recoveryDeduction,2),
                    'net_pay'=>round(max(0,$gross+$adjust-$deductions-$statutoryEmployee-$recoveryDeduction),2),
                ]);
                $grossTotal+=$gross; $adjustTotal+=$adjust; $advanceTotal+=$deductions; $recoveryTotal+=$recoveryDeduction; $statutoryEmployeeTotal+=$statutoryEmployee; $statutoryEmployerTotal+=$statutoryEmployer; $netTotal+=max(0,$gross+$adjust-$deductions-$statutoryEmployee-$recoveryDeduction);
            }
            $run->update([
                'gross_compensation'=>round($grossTotal,2),
                'adjustments_total'=>round($adjustTotal,2),
                'cash_advance_deductions'=>round($advanceTotal,2),
                'payroll_recovery_deductions'=>round($recoveryTotal,2),
                'statutory_employee_deductions'=>round($statutoryEmployeeTotal,2),
                'statutory_employer_contributions'=>round($statutoryEmployerTotal,2),
                'net_pay'=>round($netTotal,2),
                'calculated_at'=>$this->clock->now(),
            ]);
            return $run->fresh(['lines.personnel','lines.cashAdvanceAllocations.cashAdvance','lines.recoveryAllocations.recovery.originalPayrollRun','lines.recoveryAllocations.recovery.sourceAction']);
        });
    }

    public function approve(User $actor, PayrollRun $run): PayrollRun
    {
        $this->guardBusiness($actor,$run);
        return DB::transaction(function() use($actor,$run){
            $run=PayrollRun::query()->whereKey($run->id)->with(['lines.cashAdvanceAllocations.cashAdvance.ledger','lines.recoveryAllocations.recovery'])->lockForUpdate()->firstOrFail();
            if($run->status!=='DRAFT') throw new DomainException('Only Draft payroll can be approved.');
            $exceptions=$this->approvalExceptions($actor,$run);
            $blocking=collect($exceptions)->where('severity','BLOCKING');
            if($blocking->isNotEmpty()) throw new DomainException('Payroll cannot be approved: '.$blocking->pluck('message')->take(3)->implode(' | '));
            $now=$this->clock->now();
            foreach($run->lines as $line){
                DailyCompensation::query()->where('payroll_line_id',$line->id)->where('status','IN_PAYROLL_DRAFT')->update(['status'=>'APPROVED']);
                CommissionEarning::query()->where('payroll_line_id',$line->id)->where('status','IN_PAYROLL_DRAFT')->update(['status'=>'APPROVED']);
                PayrollAdjustment::query()->where('payroll_line_id',$line->id)->where('status','IN_PAYROLL_DRAFT')->update(['status'=>'APPLIED']);
                $attendanceIds=DailyCompensation::query()->where('payroll_line_id',$line->id)->whereNotNull('attendance_id')->pluck('attendance_id');
                if($attendanceIds->isNotEmpty()) Attendance::query()->whereIn('id',$attendanceIds)->update(['payroll_locked_at'=>$now]);
                foreach($line->cashAdvanceAllocations as $allocation){
                    if($allocation->status!=='RESERVED') continue;
                    $advance=$allocation->cashAdvance;
                    $ledger=EmployeeCashAdvanceLedger::query()->create(['business_id'=>$run->business_id,'cash_advance_id'=>$advance->id,'entry_date'=>$run->pay_date,'entry_type'=>'PAYROLL_DEDUCTION','amount'=>-(float)$allocation->amount,'payroll_run_id'=>$run->id,'payroll_line_id'=>$line->id,'notes'=>'Payroll '.$run->code,'created_by'=>$actor->id]);
                    $allocation->update(['status'=>'APPLIED','ledger_entry_id'=>$ledger->id]);
                    $balance=(float)$advance->fresh('ledger')->ledger->sum('amount');
                    if($balance<=0.005)$advance->update(['status'=>'CLOSED','closed_by'=>$actor->id,'closed_at'=>$now]);
                }
                $line->update(['status'=>'APPROVED']);
            }
            $this->payrollRecoveries->applyRun($actor,$run);
            $run->update(['status'=>'APPROVED','approved_by'=>$actor->id,'approved_at'=>$now]);
            $this->audit->record(action:'APPROVE_PAYROLL',module:'payroll',entityType:PayrollRun::class,entityId:$run->id,after:$this->serializeRun($run->fresh()),actor:$actor);
            return $run->fresh(['lines.personnel','lines.cashAdvanceAllocations.cashAdvance']);
        });
    }

    public function markPaid(User $actor, PayrollRun $run, ?int $fundingAccountId = null, ?string $reference = null, ?array $fundingSplits = null): PayrollRun
    {
        $this->guardBusiness($actor,$run);
        return DB::transaction(function() use($actor,$run,$fundingAccountId,$reference,$fundingSplits){
            $run=PayrollRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            if($run->status!=='APPROVED') throw new DomainException('Only Approved payroll can be marked Paid.');

            $netPay=round((float)$run->net_pay,2);
            $requested = collect($fundingSplits ?: []);
            $normalized=[];

            if ($netPay > 0.004) {
                if ($requested->isEmpty()) {
                    $fallbackId = $fundingAccountId;
                    if (! $fallbackId) {
                        $fallbackId = ChartOfAccount::query()
                            ->where('business_id',$actor->business_id)
                            ->where('system_key','CASH_IN_BANK')
                            ->where('active',true)
                            ->where('posting_type','POSTING')
                            ->value('id');
                    }
                    if ($fallbackId) {
                        $requested = collect([[
                            'funding_account_id' => (int) $fallbackId,
                            'amount' => $netPay,
                            'reference' => $reference ?: null,
                        ]]);
                    }
                }

                if ($requested->isEmpty()) throw new DomainException('Add at least one payroll funding source.');
                if ($requested->count() > 5) throw new DomainException('Payroll can use up to 5 funding sources only.');

                $used=[];
                foreach($requested->values() as $index=>$split){
                    $accountId=(int)($split['funding_account_id']??0);
                    $amount=round((float)($split['amount']??0),2);
                    $splitReference=trim((string)($split['reference']??''));
                    if($accountId<=0 || $amount<=0.004) throw new DomainException('Every payroll funding line requires a valid source and amount.');
                    if(isset($used[$accountId])) throw new DomainException('Use each payroll funding source only once.');

                    $funding=ChartOfAccount::query()
                        ->where('business_id',$actor->business_id)
                        ->whereKey($accountId)
                        ->where('active',true)
                        ->where('posting_type','POSTING')
                        ->where('is_funding_source',true)
                        ->first();
                    if(! $funding) throw new DomainException('Select a valid payroll funding source.');

                    $used[$accountId]=true;
                    $normalized[]=[
                        'funding_account_id'=>$funding->id,
                        'amount'=>$amount,
                        'reference'=>$splitReference!==''?$splitReference:null,
                        'sort_order'=>$index+1,
                    ];
                }

                $fundedTotal=round(collect($normalized)->sum('amount'),2);
                if(abs($fundedTotal-$netPay)>0.009) {
                    throw new DomainException('Payroll funding total must equal the Net Pay.');
                }
            } elseif ($requested->sum(fn($split)=>(float)($split['amount']??0)) > 0.004 || $fundingAccountId) {
                throw new DomainException('This payroll has zero Net Pay and does not require a funding source.');
            }

            PayrollPaymentSplit::query()->where('payroll_run_id',$run->id)->delete();
            foreach($normalized as $split){
                PayrollPaymentSplit::query()->create([
                    'payroll_run_id'=>$run->id,
                    'business_id'=>$run->business_id,
                    'funding_account_id'=>$split['funding_account_id'],
                    'amount'=>$split['amount'],
                    'reference'=>$split['reference'],
                    'sort_order'=>$split['sort_order'],
                    'created_by'=>$actor->id,
                ]);
            }

            $first=$normalized[0] ?? null;
            $now=$this->clock->now();
            PayrollLine::query()->where('payroll_run_id',$run->id)->update(['status'=>'PAID']);
            DailyCompensation::query()->where('payroll_run_id',$run->id)->where('status','APPROVED')->update(['status'=>'PAID']);
            CommissionEarning::query()->where('payroll_run_id',$run->id)->where('status','APPROVED')->update(['status'=>'PAID']);
            $run->update([
                'status'=>'PAID',
                'paid_by'=>$actor->id,
                'paid_at'=>$now,
                'payment_account_id'=>$first['funding_account_id'] ?? null,
                'payment_reference'=>$first ? (count($normalized)===1 ? $first['reference'] : 'Split funding') : 'No cash disbursement',
            ]);
            $fresh=$run->fresh(['lines.personnel','paymentAccount','paymentSplits.fundingAccount']);
            $this->audit->record(action:'PAY_PAYROLL',module:'payroll',entityType:PayrollRun::class,entityId:$run->id,after:$this->serializeRun($fresh,true),actor:$actor);
            return $fresh;
        });
    }

    public function cancelDraft(User $actor, PayrollRun $run): PayrollRun
    {
        $this->guardBusiness($actor,$run);
        return DB::transaction(function() use($run){
            $run=PayrollRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            if($run->status!=='DRAFT') throw new DomainException('Only Draft payroll can be cancelled.');
            $this->releaseDraftSources($run);
            PayrollCashAdvanceAllocation::query()->where('payroll_run_id',$run->id)->delete();
            PayrollLine::query()->where('payroll_run_id',$run->id)->delete();
            $run->update(['status'=>'CANCELLED','gross_compensation'=>0,'adjustments_total'=>0,'cash_advance_deductions'=>0,'payroll_recovery_deductions'=>0,'statutory_employee_deductions'=>0,'statutory_employer_contributions'=>0,'net_pay'=>0]);
            return $run->fresh();
        });
    }

    public function createAdjustment(User $actor,array $data): PayrollAdjustment
    {
        $personnel=Personnel::query()->where('business_id',$actor->business_id)->whereKey($data['personnel_id'])->firstOrFail();
        $locked=PayrollRun::query()->where('business_id',$actor->business_id)->whereIn('status',['APPROVED','PAID'])->whereDate('period_start','<=',$data['effective_date'])->whereDate('period_end','>=',$data['effective_date'])->exists();
        if($locked) throw new DomainException('This date is already inside Approved/Paid payroll. Create the correction with a future payroll effective date.');
        $now=$this->clock->now();
        $adjustment=PayrollAdjustment::query()->create(['business_id'=>$actor->business_id,'code'=>$this->codes->next('PAYROLL_ADJUSTMENT:'.$now->format('my'),'PADJ-'.$now->format('my').'-',5,$actor->business_id),'personnel_id'=>$personnel->id,'effective_date'=>$data['effective_date'],'adjustment_type'=>$data['adjustment_type']??'OTHER','amount'=>$data['amount'],'description'=>$data['description'],'reason'=>$data['reason'],'status'=>'OPEN','created_by'=>$actor->id]);
        $this->audit->record(action:'CREATE_PAYROLL_ADJUSTMENT',module:'payroll',entityType:PayrollAdjustment::class,entityId:$adjustment->id,after:$adjustment->toArray(),reason:(string)$data['reason'],actor:$actor);
        return $adjustment;
    }

    public function reverseAdjustment(User $actor, PayrollAdjustment $adjustment, ?string $reason = null): PayrollAdjustment
    {
        if((int)$adjustment->business_id!==(int)$actor->business_id)abort(404);
        if($adjustment->status!=='OPEN') throw new DomainException('Only Open payroll adjustments can be reversed.');
        $reason=trim((string)$reason) ?: 'Open payroll adjustment reversed before payroll approval.';
        $before=$adjustment->toArray();
        $adjustment->update(['status'=>'REVERSED']);
        $fresh=$adjustment->refresh();
        $this->audit->record(action:'REVERSE_PAYROLL_ADJUSTMENT',module:'payroll',entityType:PayrollAdjustment::class,entityId:$fresh->id,before:$before,after:$fresh->toArray(),reason:$reason,actor:$actor);
        return $fresh;
    }

    private function serializeAdjustments(User $actor): Collection
    {
        $rows=PayrollAdjustment::query()->where('business_id',$actor->business_id)->whereIn('status',['OPEN','IN_PAYROLL_DRAFT'])->with('personnel')->orderByDesc('effective_date')->get();
        $pending=ReversalApprovalRequest::query()->where('business_id',$actor->business_id)->where('source_type','PAYROLL_ADJUSTMENT')->whereIn('source_id',$rows->pluck('id'))->where('status','PENDING')->with(['requester','reviewer'])->get()->keyBy('source_id');
        return $rows->map(fn($a)=>[
            'id'=>$a->id,'code'=>$a->code,'effective_date'=>$a->effective_date?->toDateString(),
            'personnel'=>['id'=>$a->personnel_id,'name'=>$a->personnel?->full_name],
            'adjustment_type'=>$a->adjustment_type,'amount'=>(float)$a->amount,'description'=>$a->description,'reason'=>$a->reason,'status'=>$a->status,
            'pending_reversal'=>\App\Services\Accounting\ReversalApprovalService::serialize($pending->get($a->id)),
        ])->values();
    }

    public function waiveCashAdvance(User $actor, PayrollRun $run, EmployeeCashAdvance $advance, string $reason): PayrollRun
    {
        $this->guardBusiness($actor,$run);
        $this->assertManager($actor);
        if($run->status!=='DRAFT') throw new DomainException('Cash advance deductions can only be waived while payroll is Draft.');
        if((int)$advance->business_id!==(int)$actor->business_id) abort(404);
        if((int)$advance->personnel_id<=0 || $advance->status!=='ACTIVE') throw new DomainException('Only an active cash advance can be waived for payroll.');
        $line=PayrollLine::query()->where('payroll_run_id',$run->id)->where('personnel_id',$advance->personnel_id)->first();
        if(!$line) throw new DomainException('This employee is not included in the current Payroll Draft.');
        $balance=max(0,round((float)$advance->loadMissing('ledger')->ledger->sum('amount'),2));
        $scheduled=round(min((float)$advance->deduction_per_payroll,$balance,max(0,(float)$line->gross_compensation+(float)$line->adjustments_total)),2);
        if($scheduled<=0) throw new DomainException('There is no scheduled cash advance deduction to waive for this payroll.');
        $waiver=PayrollCashAdvanceWaiver::query()->updateOrCreate(
            ['payroll_run_id'=>$run->id,'cash_advance_id'=>$advance->id],
            ['business_id'=>$actor->business_id,'payroll_line_id'=>$line->id,'scheduled_amount'=>$scheduled,'waived_amount'=>$scheduled,'status'=>'WAIVED','reason'=>trim($reason),'waived_by'=>$actor->id,'waived_at'=>$this->clock->now(),'revoked_by'=>null,'revoked_at'=>null,'revocation_reason'=>null]
        );
        $this->audit->record(action:'WAIVE_CASH_ADVANCE_PAYROLL_DEDUCTION',module:'payroll',entityType:PayrollCashAdvanceWaiver::class,entityId:$waiver->id,after:$waiver->toArray(),reason:trim($reason),actor:$actor);
        return $this->recalculate($actor,$run->fresh());
    }

    public function restoreCashAdvance(User $actor, PayrollRun $run, EmployeeCashAdvance $advance, string $reason): PayrollRun
    {
        $this->guardBusiness($actor,$run);
        $this->assertManager($actor);
        if($run->status!=='DRAFT') throw new DomainException('Cash advance waiver can only be changed while payroll is Draft.');
        $waiver=PayrollCashAdvanceWaiver::query()->where('payroll_run_id',$run->id)->where('cash_advance_id',$advance->id)->where('status','WAIVED')->first();
        if(!$waiver) throw new DomainException('No active cash advance waiver was found for this payroll.');
        $before=$waiver->toArray();
        $waiver->update(['status'=>'REVOKED','revoked_by'=>$actor->id,'revoked_at'=>$this->clock->now(),'revocation_reason'=>trim($reason)]);
        $this->audit->record(action:'RESTORE_CASH_ADVANCE_PAYROLL_DEDUCTION',module:'payroll',entityType:PayrollCashAdvanceWaiver::class,entityId:$waiver->id,before:$before,after:$waiver->fresh()->toArray(),reason:trim($reason),actor:$actor);
        return $this->recalculate($actor,$run->fresh());
    }

    public function approvalExceptions(User $actor, PayrollRun $run): array
    {
        $this->guardBusiness($actor,$run);
        $exceptions=[];
        $scopePersonnel=function($query) use($run){ if($run->branch_id)$query->where('branch_id',$run->branch_id); };
        if(!$run->lines()->exists()) $exceptions[]=['code'=>'NO_PAYROLL_LINES','severity'=>'BLOCKING','message'=>'No eligible payroll lines were calculated for this run.'];
        if($run->lines()->where('net_pay','<',0)->exists()) $exceptions[]=['code'=>'NEGATIVE_NET','severity'=>'BLOCKING','message'=>'At least one payroll line has a negative Net Pay.'];

        $pendingAttendance=Attendance::query()->where('business_id',$run->business_id)->whereDate('attendance_date','>=',$run->period_start)->whereDate('attendance_date','<=',$run->period_end)->whereIn('workflow_status',['WORKING','COMPLETED'])
            ->when($run->branch_id,fn($q)=>$q->where('branch_id',$run->branch_id))->count();
        if($pendingAttendance>0)$exceptions[]=['code'=>'PENDING_ATTENDANCE','severity'=>'BLOCKING','message'=>$pendingAttendance.' attendance record(s) are still Working or Awaiting Approval inside this payroll period.'];

        $pendingLeave=HrLeaveRequest::query()->where('business_id',$run->business_id)->where('status','PENDING')->whereDate('start_date','<=',$run->period_end)->whereDate('end_date','>=',$run->period_start)
            ->when($run->branch_id,fn($q)=>$q->whereHas('personnel',fn($p)=>$p->where('branch_id',$run->branch_id)))->count();
        if($pendingLeave>0)$exceptions[]=['code'=>'PENDING_LEAVE','severity'=>'BLOCKING','message'=>$pendingLeave.' Leave/Day Off request(s) are still pending inside this payroll period.'];

        $openDaily=DailyCompensation::query()->where('business_id',$run->business_id)->where('status','OPEN')->whereDate('work_date','>=',$run->period_start)->whereDate('work_date','<=',$run->period_end)
            ->when($run->branch_id,fn($q)=>$q->whereHas('personnel',fn($p)=>$p->where('branch_id',$run->branch_id)))->count();
        $openAdjust=PayrollAdjustment::query()->where('business_id',$run->business_id)->where('status','OPEN')->whereDate('effective_date','>=',$run->period_start)->whereDate('effective_date','<=',$run->period_end)
            ->when($run->branch_id,fn($q)=>$q->whereHas('personnel',fn($p)=>$p->where('branch_id',$run->branch_id)))->count();
        $openEarnings=CommissionEarning::query()->where('business_id',$run->business_id)->where('status','OPEN')->whereDate('work_date','>=',$run->period_start)->whereDate('work_date','<=',$run->period_end)
            ->when($run->branch_id,fn($q)=>$q->whereHas('personnel',fn($p)=>$p->where('branch_id',$run->branch_id)))->count();
        $newRecoveries=$this->payrollRecoveries->createdAfterCalculation($run);
        $postedOperationalActions=0;
        if($run->calculated_at){
            $postedOperationalActions=TransactionActionRequest::query()
                ->where('business_id',$run->business_id)
                ->where('status','POSTED')
                ->where('posted_at','>',$run->calculated_at)
                ->when($run->branch_id,fn($q)=>$q->where('branch_id',$run->branch_id))
                ->whereHas('sale',fn($q)=>$q
                    ->whereNotNull('booking_id')
                    ->whereDate('sale_date','>=',$run->period_start)
                    ->whereDate('sale_date','<=',$run->period_end))
                ->count();
        }
        if($openDaily+$openAdjust+$openEarnings+$newRecoveries+$postedOperationalActions>0)$exceptions[]=['code'=>'DRAFT_OUT_OF_DATE','severity'=>'BLOCKING','message'=>'Payroll sources changed after the last calculation, including transaction corrections/reversals where applicable. Recalculate the Payroll Draft before approval.'];

        $scheduledAbsences=Personnel::query()->where('business_id',$run->business_id)->where('status','ACTIVE')->when($run->branch_id,fn($q)=>$q->where('branch_id',$run->branch_id))->whereHas('compensationRule',fn($q)=>$q->where('code','!=','NONE'))->count();
        if($scheduledAbsences===0)$exceptions[]=['code'=>'NO_COMPENSATED_PERSONNEL','severity'=>'WARNING','message'=>'No active personnel with operational compensation are in this payroll scope.'];
        return $exceptions;
    }

    private function assertManager(User $actor): void
    {
        $actor->loadMissing('role');
        if(strtoupper((string)$actor->role?->code)!=='ROLE-MGR'){
            throw new DomainException('Only a Manager can waive or restore a cash advance deduction for a payroll run.');
        }
    }

    public function payslips(User $actor,array $filters=[]):array
    {
        $query=PayrollLine::query()->where('business_id',$actor->business_id)->whereIn('status',['APPROVED','PAID'])->with(['run','personnel']);
        if(($filters['personnel_id']??'')!=='')$query->where('personnel_id',(int)$filters['personnel_id']);
        $rows=$query->orderByDesc(PayrollRun::select('period_end')->whereColumn('payroll_runs.id','payroll_lines.payroll_run_id'))->get();
        return ['rows'=>$rows->map(fn($l)=>$this->serializeLine($l))->values(),'personnel'=>Personnel::query()->where('business_id',$actor->business_id)->where('status','ACTIVE')->orderBy('last_name')->orderBy('first_name')->get()->map(fn($p)=>['id'=>$p->id,'name'=>$p->full_name,'code'=>$p->code])->values()];
    }

    public function myPayroll(User $actor): array
    {
        $personnel = Personnel::query()
            ->where('business_id', $actor->business_id)
            ->where('linked_user_id', $actor->id)
            ->with(['position', 'compensationRule'])
            ->first();

        if (! $personnel) {
            throw new DomainException('Your user account is not linked to a Personnel record.');
        }

        $compensationRule = strtoupper((string) ($personnel->compensationRule?->code ?? 'NONE'));
        if ($compensationRule === 'NONE') {
            throw new DomainException('My Payroll is not available because this Personnel record has no operational compensation setup.');
        }

        $today = $this->clock->today();

        $history = PayrollLine::query()
            ->where('business_id', $actor->business_id)
            ->where('personnel_id', $personnel->id)
            ->whereIn('status', ['APPROVED', 'PAID'])
            ->with('run')
            ->orderByDesc('id')
            ->get();

        $pendingLine = PayrollLine::query()
            ->where('business_id', $actor->business_id)
            ->where('personnel_id', $personnel->id)
            ->where('status', 'DRAFT')
            ->whereHas('run', fn ($query) => $query->where('status', 'DRAFT'))
            ->with('run')
            ->orderByDesc('id')
            ->first();

        if ($pendingLine) {
            $runningBasic = $compensationRule === 'GUARANTEED_VS_COMMISSION'
                ? round((float) $pendingLine->guaranteed_top_up + (float) $pendingLine->ot_pay, 2)
                : round((float) $pendingLine->hourly_basic_pay + (float) $pendingLine->ot_pay, 2);
            $runningCommission = round((float) $pendingLine->commission_amount, 2);
            $runningAdjustments = round((float) $pendingLine->adjustments_total, 2);
            $nextCashAdvanceDeduction = round((float) $pendingLine->cash_advance_deductions, 2);
            $nextPayrollRecovery = round((float) $pendingLine->payroll_recovery_deductions, 2);
            $projectedNet = round((float) $pendingLine->net_pay, 2);
        } else {
            $dailyRows = DailyCompensation::query()
                ->where('business_id', $actor->business_id)
                ->where('personnel_id', $personnel->id)
                ->where('status', 'OPEN')
                ->get();

            $runningBasic = round((float) $dailyRows->sum(function ($row) {
                if (strtoupper((string) $row->compensation_rule_snapshot) === 'GUARANTEED_VS_COMMISSION') {
                    return (float) $row->guaranteed_top_up + (float) $row->ot_pay;
                }
                return (float) $row->hourly_basic_pay + (float) $row->ot_pay;
            }), 2);
            $runningCommission = round((float) $dailyRows->sum('confirmed_commission'), 2);
            $runningAdjustments = round((float) PayrollAdjustment::query()
                ->where('business_id', $actor->business_id)
                ->where('personnel_id', $personnel->id)
                ->where('status', 'OPEN')
                ->sum('amount'), 2);

            $availableBeforeAdvance = max(0, round($runningBasic + $runningCommission + $runningAdjustments, 2));
            $nextCashAdvanceDeduction = $this->estimateNextCashAdvanceDeduction($actor, $personnel, $availableBeforeAdvance);
            $availableAfterAdvance = max(0, round($availableBeforeAdvance - $nextCashAdvanceDeduction, 2));
            $openRecoveryBalance = round((float) PayrollRecovery::query()
                ->where('business_id', $actor->business_id)
                ->where('personnel_id', $personnel->id)
                ->whereIn('status', ['OPEN', 'PARTIAL'])
                ->sum('remaining_amount'), 2);
            $nextPayrollRecovery = round(min($openRecoveryBalance, $availableAfterAdvance), 2);
            $projectedNet = round(max(0, $availableAfterAdvance - $nextPayrollRecovery), 2);
        }

        $outstandingPayrollRecovery = round((float) PayrollRecovery::query()
            ->where('business_id', $actor->business_id)
            ->where('personnel_id', $personnel->id)
            ->whereIn('status', ['OPEN', 'PARTIAL'])
            ->sum('remaining_amount'), 2);

        $todayCommission = round((float) CommissionEarning::query()
            ->where('business_id', $actor->business_id)
            ->where('personnel_id', $personnel->id)
            ->whereDate('work_date', $today)
            ->whereIn('status', ['OPEN', 'IN_PAYROLL_DRAFT', 'APPROVED', 'PAID'])
            ->sum('commission_amount'), 2);

        $todayProjected = round((float) DailyCompensation::query()
            ->where('business_id', $actor->business_id)
            ->where('personnel_id', $personnel->id)
            ->whereDate('work_date', $today)
            ->whereIn('status', ['OPEN', 'IN_PAYROLL_DRAFT', 'APPROVED', 'PAID'])
            ->sum('gross_daily_projection'), 2);

        $advances = EmployeeCashAdvance::query()
            ->where('business_id', $actor->business_id)
            ->where('personnel_id', $personnel->id)
            ->where('status', 'ACTIVE')
            ->with('ledger')
            ->orderBy('issued_date')
            ->orderBy('id')
            ->get();

        $remainingAdvance = round((float) $advances->sum(fn ($advance) => max(0, (float) $advance->ledger->sum('amount'))), 2);

        $lastPaid = $history->first(fn ($line) => $line->status === 'PAID');

        $commissionTransactions = $this->commissionTransactionsForPersonnel($actor, $personnel);

        return [
            'personnel' => [
                'id' => $personnel->id,
                'code' => $personnel->code,
                'name' => $personnel->full_name,
                'position' => $personnel->position?->display_value,
                'compensation_rule' => $compensationRule,
                'hourly_rate' => (float) $personnel->hourly_rate,
                'guaranteed_daily_pay' => (float) $personnel->guaranteed_daily_pay,
            ],
            'summary' => [
                'today_confirmed_commission' => $todayCommission,
                'today_projected_compensation' => $todayProjected,
                'running_commission' => $runningCommission,
                'outstanding_payroll_recovery' => $outstandingPayrollRecovery,
                'projected_running_net' => $projectedNet,
            ],
            'running_payroll' => [
                'basic_and_guarantee_top_up' => $runningBasic,
                'commission' => $runningCommission,
                'additions' => max(0, $runningAdjustments),
                'other_deductions' => abs(min(0, $runningAdjustments)),
                'next_cash_advance_deduction' => $nextCashAdvanceDeduction,
                'prior_payroll_recovery' => $nextPayrollRecovery,
                'outstanding_recovery_balance' => $outstandingPayrollRecovery,
                'projected_net' => $projectedNet,
                'payroll_code' => $pendingLine?->run?->code,
                'payroll_status' => $pendingLine?->run?->status,
            ],
            'cash_advance' => [
                'active_advances' => $advances->count(),
                'remaining_balance' => $remainingAdvance,
                'next_scheduled_deduction' => $nextCashAdvanceDeduction,
                'last_paid_payroll' => $lastPaid?->run?->code,
                'last_pay_date' => $lastPaid?->run?->pay_date?->toDateString(),
                'last_payslip_id' => $lastPaid?->id,
            ],
            'commission_transactions' => $commissionTransactions,
            'rows' => $history->map(fn ($line) => $this->serializeLine($line))->values(),
        ];
    }

    private function estimateNextCashAdvanceDeduction(User $actor, Personnel $personnel, float $capacity): float
    {
        if ($capacity <= 0.005) {
            return 0.0;
        }

        $deductions = 0.0;
        $advances = EmployeeCashAdvance::query()
            ->where('business_id', $actor->business_id)
            ->where('personnel_id', $personnel->id)
            ->where('status', 'ACTIVE')
            ->where('deduction_per_payroll', '>', 0)
            ->with('ledger')
            ->orderBy('issued_date')
            ->orderBy('id')
            ->get();

        foreach ($advances as $advance) {
            if ($capacity - $deductions <= 0.005) {
                break;
            }

            $balance = max(0, round((float) $advance->ledger->sum('amount'), 2));
            $amount = min((float) $advance->deduction_per_payroll, $balance, $capacity - $deductions);
            $deductions += round(max(0, $amount), 2);
        }

        return round($deductions, 2);
    }

    /**
     * Employee-facing commission activity is presented as the current economic
     * commission per transaction line. The immutable CommissionEarning ledger
     * remains untouched and is still available to admin/audit views.
     */
    private function commissionTransactionsForPersonnel(User $actor, Personnel $personnel): Collection
    {
        $rows = CommissionEarning::query()
            ->where('business_id', $actor->business_id)
            ->where('personnel_id', $personnel->id)
            ->where('status', '<>', 'REVERSED')
            ->with(['service', 'sale.customer', 'booking.customer', 'saleItem'])
            ->orderByDesc('work_date')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        // Historical correction sequences may contain an auditable positive
        // adjustment, a later canonical earning, and its balancing negative
        // adjustment. Suppress only that redundant presentation family.
        $rows = $this->commissionEarnings->withoutRedundantCorrectionDuplicates($rows);

        return $rows
            ->groupBy(function (CommissionEarning $earning): string {
                if (strtoupper((string) $earning->earning_type) === 'INCENTIVE') {
                    return 'INCENTIVE:'.$earning->id;
                }

                return $earning->sale_item_id
                    ? 'SALE_ITEM:'.$earning->sale_item_id
                    : 'EARNING:'.$earning->id;
            })
            ->map(function (Collection $group): ?array {
                /** @var CommissionEarning $latest */
                $latest = $group->sortByDesc('id')->first();
                /** @var CommissionEarning $representative */
                $representative = $group->first(fn (CommissionEarning $row) =>
                    in_array(strtoupper((string) $row->earning_type), ['SERVICE', 'RETAIL', 'INCENTIVE'], true)
                    && (float) $row->commission_amount > 0
                ) ?: $group->first(fn (CommissionEarning $row) => (float) $row->commission_amount > 0) ?: $latest;

                $net = round((float) $group->sum('commission_amount'), 2);
                if (abs($net) <= 0.009) {
                    return null;
                }

                $earningType = strtoupper((string) $representative->earning_type);
                if ($earningType === 'ADJUSTMENT' && $representative->saleItem) {
                    $earningType = strtoupper((string) $representative->saleItem->item_type) === 'PRODUCT' ? 'RETAIL' : 'SERVICE';
                }
                if ($earningType === '') {
                    $earningType = $representative->visit_retail_line_id ? 'RETAIL' : 'SERVICE';
                }

                return [
                    'id' => $representative->id,
                    'date' => $representative->work_date?->toDateString(),
                    'earning_type' => $earningType,
                    'service' => $representative->service_name_snapshot ?: $representative->service?->name,
                    'item' => $representative->service_name_snapshot ?: $representative->service?->name,
                    'customer' => $representative->sale?->customer?->full_name ?: $representative->booking?->customer?->full_name,
                    'booking_code' => $representative->booking?->code,
                    'sale_code' => $representative->sale?->code,
                    'service_value' => (float) $representative->basis_amount,
                    'commission' => $net,
                    'status' => $latest->status,
                ];
            })
            ->filter()
            ->sortByDesc(fn (array $row) => ($row['date'] ?? '').'|'.str_pad((string) $row['id'], 12, '0', STR_PAD_LEFT))
            ->take(25)
            ->values();
    }

    private function releaseDraftSources(PayrollRun $run):void
    {
        DailyCompensation::query()->where('payroll_run_id',$run->id)->where('status','IN_PAYROLL_DRAFT')->update(['status'=>'OPEN','payroll_run_id'=>null,'payroll_line_id'=>null]);
        CommissionEarning::query()->where('payroll_run_id',$run->id)->where('status','IN_PAYROLL_DRAFT')->update(['status'=>'OPEN','payroll_run_id'=>null,'payroll_line_id'=>null]);
        PayrollAdjustment::query()->where('payroll_run_id',$run->id)->where('status','IN_PAYROLL_DRAFT')->update(['status'=>'OPEN','payroll_run_id'=>null,'payroll_line_id'=>null]);
        $this->payrollRecoveries->releaseRunReservations($run);
    }
    private function guardBusiness(User $actor,PayrollRun $run):void{if((int)$actor->business_id!==(int)$run->business_id)abort(404);}
    public function serializeRun(PayrollRun $r, bool $withLines=false): array
    {
        $base=[
            'id'=>$r->id,'code'=>$r->code,'period_start'=>$r->period_start?->toDateString(),'period_end'=>$r->period_end?->toDateString(),
            'pay_date'=>$r->pay_date?->toDateString(),'branch'=>$r->branch?['id'=>$r->branch->id,'name'=>$r->branch->name,'code'=>$r->branch->code]:null,
            'status'=>$r->status,'gross_compensation'=>(float)$r->gross_compensation,'adjustments_total'=>(float)$r->adjustments_total,
            'cash_advance_deductions'=>(float)$r->cash_advance_deductions,'payroll_recovery_deductions'=>(float)$r->payroll_recovery_deductions,'statutory_employee_deductions'=>(float)$r->statutory_employee_deductions,'statutory_employer_contributions'=>(float)$r->statutory_employer_contributions,'net_pay'=>(float)$r->net_pay,'notes'=>$r->notes,'calculated_at'=>$r->calculated_at?->toIso8601String(),
            'payment_account'=>$r->paymentAccount?['id'=>$r->paymentAccount->id,'code'=>$r->paymentAccount->code,'name'=>$r->paymentAccount->name]:null,'payment_reference'=>$r->payment_reference,
            'payment_splits'=>$r->relationLoaded('paymentSplits') ? $r->paymentSplits->map(fn($split)=>['id'=>$split->id,'funding_account_id'=>$split->funding_account_id,'funding_source'=>$split->fundingAccount?['id'=>$split->fundingAccount->id,'code'=>$split->fundingAccount->code,'name'=>$split->fundingAccount->name]:null,'amount'=>(float)$split->amount,'reference'=>$split->reference])->values() : [],
            'lines_count'=>$r->lines_count??($r->relationLoaded('lines')?$r->lines->count():null),'approved_at'=>$r->approved_at?->toIso8601String(),'paid_at'=>$r->paid_at?->toIso8601String(),
        ];
        if($withLines){
            $r->loadMissing(['lines.cashAdvanceAllocations.cashAdvance','lines.recoveryAllocations.recovery.originalPayrollRun','lines.recoveryAllocations.recovery.sourceAction','cashAdvanceWaivers.cashAdvance']);
            $base['lines']=$r->lines->map(function($line) use($r){
                $payload=$this->serializeLine($line);
                $allocations=$line->cashAdvanceAllocations->keyBy('cash_advance_id');
                $waivers=$r->cashAdvanceWaivers->keyBy('cash_advance_id');
                $advanceIds=$allocations->keys()->merge($waivers->keys())->unique();
                $payload['cash_advance_options']=$advanceIds->map(function($advanceId) use($allocations,$waivers){
                    $allocation=$allocations->get($advanceId); $waiver=$waivers->get($advanceId); $advance=$allocation?->cashAdvance ?: $waiver?->cashAdvance;
                    return [
                        'cash_advance_id'=>(int)$advanceId,'code'=>$advance?->code,
                        'deduction_amount'=>(float)($allocation?->amount ?? 0),'scheduled_amount'=>(float)($waiver?->scheduled_amount ?? $allocation?->amount ?? 0),
                        'waived'=>$waiver?->status==='WAIVED','waiver_reason'=>$waiver?->reason,
                    ];
                })->values();
                return $payload;
            })->values();
        }
        return $base;
    }

    public function serializeLine(PayrollLine $l): array
    {
        $l->loadMissing(['run','recoveryAllocations.recovery.originalPayrollRun','recoveryAllocations.recovery.sourceAction']);
        return [
            'id'=>$l->id,'payroll_run_id'=>$l->payroll_run_id,'payroll_code'=>$l->run?->code,'period_start'=>$l->run?->period_start?->toDateString(),'period_end'=>$l->run?->period_end?->toDateString(),'pay_date'=>$l->run?->pay_date?->toDateString(),
            'personnel'=>['id'=>$l->personnel_id,'code'=>$l->personnel_code_snapshot,'name'=>$l->personnel_name_snapshot,'position'=>$l->position_snapshot],
            'hourly_basic_pay'=>(float)$l->hourly_basic_pay,'ot_pay'=>(float)$l->ot_pay,'commission_amount'=>(float)$l->commission_amount,'guaranteed_top_up'=>(float)$l->guaranteed_top_up,
            'gross_compensation'=>(float)$l->gross_compensation,'adjustments_total'=>(float)$l->adjustments_total,'cash_advance_deductions'=>(float)$l->cash_advance_deductions,'payroll_recovery_deductions'=>(float)$l->payroll_recovery_deductions,
            'sss_employee'=>(float)$l->sss_employee,'sss_employer'=>(float)$l->sss_employer,'philhealth_employee'=>(float)$l->philhealth_employee,'philhealth_employer'=>(float)$l->philhealth_employer,'pagibig_employee'=>(float)$l->pagibig_employee,'pagibig_employer'=>(float)$l->pagibig_employer,'statutory_employee_deductions'=>(float)$l->statutory_employee_deductions,'statutory_employer_contributions'=>(float)$l->statutory_employer_contributions,
            'net_pay'=>(float)$l->net_pay,'status'=>$l->status,
            'payroll_recoveries'=>$l->recoveryAllocations->map(function($allocation){
                $recovery=$allocation->recovery;
                return [
                    'id'=>$recovery?->id,'code'=>$recovery?->code,'type'=>$recovery?->recovery_type,'amount'=>(float)$allocation->amount,'status'=>$allocation->status,
                    'source_label'=>$recovery?->source_label,'reason'=>$recovery?->reason,'original_payroll_code'=>$recovery?->originalPayrollRun?->code,'source_action_code'=>$recovery?->sourceAction?->code,
                    'remaining_balance'=>$recovery ? (float)$recovery->remaining_amount : 0,
                ];
            })->values(),
        ];
    }

}
