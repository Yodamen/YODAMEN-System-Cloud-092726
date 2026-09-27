<?php
namespace App\Services\Hr;

use App\Models\EmployeeCashAdvance;
use App\Models\EmployeeCashAdvanceLedger;
use App\Models\Personnel;
use App\Models\User;
use App\Services\Accounting\AccountingJournalService;
use App\Services\Foundation\BusinessClock;
use App\Services\Foundation\CodeSequenceService;
use App\Services\Security\AuditService;
use DomainException;
use Illuminate\Support\Facades\DB;

class CashAdvanceService
{
    public function __construct(private readonly CodeSequenceService $codes, private readonly BusinessClock $clock, private readonly AuditService $audit, private readonly AccountingJournalService $accounting) {}

    public function list(User $actor, array $filters=[]): array
    {
        $status = strtoupper((string)($filters['status'] ?? 'ALL'));
        $personnelId = ($filters['personnel_id'] ?? '') !== '' ? (int)$filters['personnel_id'] : null;

        $query = EmployeeCashAdvance::query()->where('business_id',$actor->business_id)
            ->with(['personnel.position','ledger.processor'])
            ->when($personnelId, fn($q)=>$q->where('personnel_id',$personnelId));

        if ($status === 'WRITTEN_OFF') {
            $query->where('status','CLOSED')->whereHas('ledger', fn($q)=>$q->where('entry_type','WRITE_OFF'));
        } elseif ($status === 'FULLY_PAID') {
            $query->where('status','CLOSED')->whereDoesntHave('ledger', fn($q)=>$q->where('entry_type','WRITE_OFF'));
        } elseif (in_array($status, ['DRAFT','ACTIVE','CLOSED'], true)) {
            $query->where('status',$status);
        }

        $rows = $query->orderByDesc('issued_date')->orderByDesc('id')->get();

        return [
            'rows'=>$rows->map(fn($r)=>$this->serialize($r))->values(),
            'personnel'=>Personnel::query()->where('business_id',$actor->business_id)->where('status','ACTIVE')->orderBy('last_name')->orderBy('first_name')->get()->map(fn($p)=>['id'=>$p->id,'code'=>$p->code,'name'=>$p->full_name])->values(),
            'totals'=>[
                'outstanding'=>round($rows->sum(fn($r)=>max(0,$this->balance($r))),2),
                'active'=>$rows->where('status','ACTIVE')->count(),
                'draft'=>$rows->where('status','DRAFT')->count(),
                'fully_paid'=>$rows->filter(fn($r)=>$this->displayStatus($r)==='FULLY_PAID')->count(),
                'written_off'=>$rows->filter(fn($r)=>$this->displayStatus($r)==='WRITTEN_OFF')->count(),
            ],
        ];
    }

    public function create(User $actor, array $data): EmployeeCashAdvance
    {
        $personnel = Personnel::query()->where('business_id',$actor->business_id)->whereKey($data['personnel_id'])->firstOrFail();
        if ((float)$data['original_amount'] <= 0) throw new DomainException('Cash advance amount must be greater than zero.');
        if ((float)($data['deduction_per_payroll'] ?? 0) < 0) throw new DomainException('Payroll deduction cannot be negative.');
        $now=$this->clock->now();
        return EmployeeCashAdvance::query()->create([
            'business_id'=>$actor->business_id,
            'code'=>$this->codes->next('EMP_CASH_ADVANCE:'.$now->format('my'),'ECA-'.$now->format('my').'-',5,$actor->business_id),
            'personnel_id'=>$personnel->id,'issued_date'=>$data['issued_date'],'original_amount'=>$data['original_amount'],
            'deduction_per_payroll'=>$data['deduction_per_payroll'] ?? 0,'purpose'=>$data['purpose'] ?? null,'notes'=>$data['notes'] ?? null,
            'status'=>'DRAFT','created_by'=>$actor->id,
        ]);
    }

    public function approve(User $actor, EmployeeCashAdvance $advance): EmployeeCashAdvance
    {
        $this->guardBusiness($actor,$advance);
        return DB::transaction(function() use($actor,$advance){
            $advance=EmployeeCashAdvance::query()->whereKey($advance->id)->lockForUpdate()->firstOrFail();
            if ($advance->status !== 'DRAFT') throw new DomainException('Only Draft cash advances can be approved.');
            EmployeeCashAdvanceLedger::query()->create([
                'business_id'=>$advance->business_id,'cash_advance_id'=>$advance->id,'entry_date'=>$advance->issued_date,
                'entry_type'=>'ISSUE','amount'=>$advance->original_amount,'notes'=>'Approved cash advance issuance.','created_by'=>$actor->id,
            ]);
            $advance->update(['status'=>'ACTIVE','approved_by'=>$actor->id,'approved_at'=>$this->clock->now()]);
            $this->audit->record(action:'APPROVE_CASH_ADVANCE',module:'cash-advances',entityType:EmployeeCashAdvance::class,entityId:$advance->id,after:$this->serialize($advance->fresh(['personnel','ledger'])),actor:$actor);
            return $advance->fresh(['personnel','ledger']);
        });
    }

    public function recordRepayment(User $actor, EmployeeCashAdvance $advance, float $amount, string $date, ?string $notes=null): EmployeeCashAdvance
    {
        $this->guardBusiness($actor,$advance);
        if ($amount <= 0) throw new DomainException('Repayment amount must be greater than zero.');
        return DB::transaction(function() use($actor,$advance,$amount,$date,$notes){
            $advance=EmployeeCashAdvance::query()->whereKey($advance->id)->with('ledger')->lockForUpdate()->firstOrFail();
            if ($advance->status !== 'ACTIVE') throw new DomainException('Repayment can only be recorded against an Active cash advance.');
            $balance=$this->balance($advance);
            if ($amount > $balance + 0.005) throw new DomainException('Repayment cannot exceed the outstanding cash advance balance.');
            EmployeeCashAdvanceLedger::query()->create(['business_id'=>$advance->business_id,'cash_advance_id'=>$advance->id,'entry_date'=>$date,'entry_type'=>'REPAYMENT','amount'=>-$amount,'notes'=>$notes,'created_by'=>$actor->id]);
            $advance=$advance->fresh(['personnel','ledger']);
            if ($this->balance($advance) <= 0.005) $advance->update(['status'=>'CLOSED','closed_by'=>$actor->id,'closed_at'=>$this->clock->now()]);
            return $advance->fresh(['personnel','ledger']);
        });
    }

    public function writeOff(User $actor, EmployeeCashAdvance $advance, string $reason): EmployeeCashAdvance
    {
        $this->guardBusiness($actor, $advance);
        $actor->loadMissing('role');
        if (! $actor->isSuperAdmin()) {
            throw new DomainException('Only a Super Admin can forgive or write off a cash advance balance. Use the per-payroll waiver when the deduction should only be skipped for one payroll run.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Write-off reason is required.');
        }

        return DB::transaction(function () use ($actor, $advance, $reason) {
            $advance=EmployeeCashAdvance::query()->whereKey($advance->id)->with('ledger')->lockForUpdate()->firstOrFail();
            $this->guardBusiness($actor, $advance);
            if ($advance->status !== 'ACTIVE') {
                throw new DomainException('Only an Active cash advance can be written off.');
            }
            $balance = max(0, $this->balance($advance));
            if ($balance <= 0.005) {
                throw new DomainException('This cash advance has no outstanding balance to write off.');
            }

            EmployeeCashAdvanceLedger::query()->create([
                'business_id' => $advance->business_id,
                'cash_advance_id' => $advance->id,
                'entry_date' => $this->clock->today(),
                'entry_type' => 'WRITE_OFF',
                'amount' => -$balance,
                'notes' => 'Approved cash advance forgiveness/write-off. Reason: '.$reason,
                'created_by' => $actor->id,
            ]);
            $this->accounting->postCashAdvanceWriteOff(
                actor: $actor,
                advance: $advance,
                amount: $balance,
                postingDate: $this->clock->today(),
                reason: $reason,
            );
            $before = $this->serialize($advance);
            $advance->update(['status' => 'CLOSED', 'closed_by' => $actor->id, 'closed_at' => $this->clock->now()]);
            $fresh = $advance->fresh(['personnel.position', 'ledger']);
            $this->audit->record(
                action: 'WRITE_OFF_CASH_ADVANCE',
                module: 'cash-advances',
                entityType: EmployeeCashAdvance::class,
                entityId: $advance->id,
                before: $before,
                after: $this->serialize($fresh),
                reason: $reason,
                actor: $actor,
            );
            return $fresh;
        });
    }

    public function balance(EmployeeCashAdvance $advance): float { return round((float)$advance->ledger->sum('amount'),2); }

    public function serialize(EmployeeCashAdvance $advance): array
    {
        $advance->loadMissing(['personnel.position','ledger.processor']);
        $manualRepayment = round((float)$advance->ledger->where('entry_type','REPAYMENT')->sum(fn($e)=>abs(min(0,(float)$e->amount))),2);
        $payrollDeduction = round((float)$advance->ledger->where('entry_type','PAYROLL_DEDUCTION')->sum(fn($e)=>abs(min(0,(float)$e->amount))),2);
        $writtenOff = round((float)$advance->ledger->where('entry_type','WRITE_OFF')->sum(fn($e)=>abs(min(0,(float)$e->amount))),2);

        return [
            'id'=>$advance->id,
            'code'=>$advance->code,
            'issued_date'=>$advance->issued_date?->toDateString(),
            'personnel'=>$advance->personnel?['id'=>$advance->personnel->id,'code'=>$advance->personnel->code,'name'=>$advance->personnel->full_name,'position'=>$advance->personnel->position?->display_value]:null,
            'original_amount'=>(float)$advance->original_amount,
            'deduction_per_payroll'=>(float)$advance->deduction_per_payroll,
            'outstanding_balance'=>max(0,$this->balance($advance)),
            'manual_repayment_total'=>$manualRepayment,
            'payroll_deduction_total'=>$payrollDeduction,
            'payment_total'=>round($manualRepayment+$payrollDeduction,2),
            'written_off_total'=>$writtenOff,
            'purpose'=>$advance->purpose,
            'notes'=>$advance->notes,
            'status'=>$advance->status,
            'display_status'=>$this->displayStatus($advance),
            'approved_at'=>$advance->approved_at?->toIso8601String(),
            'closed_at'=>$advance->closed_at?->toIso8601String(),
            'ledger'=>$advance->ledger->map(fn($e)=>[
                'id'=>$e->id,
                'entry_date'=>$e->entry_date?->toDateString(),
                'entry_type'=>$e->entry_type,
                'amount'=>(float)$e->amount,
                'payroll_run_id'=>$e->payroll_run_id,
                'payroll_line_id'=>$e->payroll_line_id,
                'notes'=>$e->notes,
                'processed_by'=>$e->processor?->display_name,
            ])->values(),
        ];
    }

    private function displayStatus(EmployeeCashAdvance $advance): string
    {
        if ($advance->status === 'DRAFT') return 'DRAFT';
        if ($advance->status === 'ACTIVE') return 'ACTIVE';
        if ($advance->ledger->contains(fn($entry)=>$entry->entry_type === 'WRITE_OFF')) return 'WRITTEN_OFF';
        return 'FULLY_PAID';
    }

    private function guardBusiness(User $actor, EmployeeCashAdvance $advance): void { if ((int)$actor->business_id !== (int)$advance->business_id) abort(404); }
}
