<?php

namespace App\Services\Accounting;

use App\Models\Branch;
use App\Models\BusinessFundingTransaction;
use App\Models\ReversalApprovalRequest;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Services\Foundation\BusinessClock;
use App\Services\Foundation\CodeSequenceService;
use App\Services\Security\AuditService;
use DomainException;
use Illuminate\Support\Facades\DB;

class BusinessFundingService
{
    public function __construct(
        private readonly AccountingJournalService $journals,
        private readonly CodeSequenceService $codes,
        private readonly BusinessClock $clock,
        private readonly AuditService $audit,
    ) {}

    public function options(User $actor): array
    {
        return [
            'branches' => Branch::query()->where('business_id', $actor->business_id)->where('status', 'ACTIVE')->orderBy('name')->get(['id','code','name']),
            'cash_accounts' => ChartOfAccount::query()->where('business_id', $actor->business_id)->where('active', true)->where('posting_type', 'POSTING')->where('is_cash_account', true)->orderBy('code')->get()->filter(fn ($a) => ! in_array($a->system_key, ['CASH_ON_HAND','PETTY_CASH'], true))->map(fn ($a) => ['id'=>$a->id,'code'=>$a->code,'name'=>$a->name])->values(),
            'funding_types' => [
                ['value' => 'CAPITAL_CONTRIBUTION', 'label' => 'Capital Contribution'],
                ['value' => 'OWNER_LOAN', 'label' => 'Owner Loan'],
            ],
        ];
    }

    public function list(User $actor): array
    {
        $rows = BusinessFundingTransaction::query()->where('business_id', $actor->business_id)->with(['branch','cashAccount'])->orderByDesc('funding_date')->orderByDesc('id')->limit(100)->get();
        $pending = ReversalApprovalRequest::query()->where('business_id',$actor->business_id)->where('source_type','BUSINESS_FUNDING')->whereIn('source_id',$rows->pluck('id'))->where('status','PENDING')->with(['requester','reviewer'])->get()->keyBy('source_id');
        return ['rows' => $rows->map(fn ($row) => $this->serialize($row, $pending->get($row->id)))->values(), 'options' => $this->options($actor)];
    }

    public function create(User $actor, array $data): BusinessFundingTransaction
    {
        $type = strtoupper((string) $data['funding_type']);
        if (! in_array($type, ['CAPITAL_CONTRIBUTION', 'OWNER_LOAN'], true)) throw new DomainException('Select Capital Contribution or Owner Loan.');
        $amount = round((float) $data['amount'], 2);
        if ($amount <= 0) throw new DomainException('Funding amount must be greater than zero.');
        $cash = ChartOfAccount::query()->where('business_id', $actor->business_id)->whereKey((int) $data['cash_account_id'])->where('active', true)->where('posting_type', 'POSTING')->where('is_cash_account', true)->first();
        if (! $cash) throw new DomainException('Select an active Posting cash/payment account.');
        if (in_array($cash->system_key, ['CASH_ON_HAND','PETTY_CASH'], true)) throw new DomainException('Record owner funding directly into a bank or e-wallet account. Petty Cash can then be replenished from an approved funding source.');
        $branchId = ! empty($data['branch_id']) ? (int) $data['branch_id'] : null;
        if ($branchId) Branch::query()->where('business_id', $actor->business_id)->whereKey($branchId)->firstOrFail();

        return DB::transaction(function () use ($actor, $data, $type, $amount, $cash, $branchId) {
            $now = $this->clock->now();
            $row = BusinessFundingTransaction::query()->create([
                'business_id' => $actor->business_id, 'branch_id' => $branchId,
                'code' => $this->codes->next('BUSINESS_FUNDING:'.$now->format('my'), 'FND-'.$now->format('my').'-', 5, $actor->business_id),
                'funding_date' => $data['funding_date'], 'funding_type' => $type, 'cash_account_id' => $cash->id,
                'amount' => $amount, 'reference' => trim((string) ($data['reference'] ?? '')) ?: null,
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null, 'status' => 'POSTED',
                'created_by' => $actor->id, 'posted_at' => $now,
            ]);
            $creditKey = $type === 'CAPITAL_CONTRIBUTION' ? 'OWNERS_CAPITAL' : 'LOANS_PAYABLE_OWNER';
            $label = $type === 'CAPITAL_CONTRIBUTION' ? 'Owner capital contribution' : 'Owner loan funding';
            $journal = $this->journals->postEntry(
                $actor->business_id, $branchId, $data['funding_date'], 'BUSINESS_FUNDING', $row->id, 'POST', $row->code, $label.' '.$row->code,
                [
                    ['account_id' => $cash->id, 'debit' => $amount, 'credit' => 0, 'memo' => $label.' received in '.$cash->name],
                    ['account_key' => $creditKey, 'debit' => 0, 'credit' => $amount, 'memo' => $label],
                ], $actor->id,
            );
            if (! $journal) throw new DomainException('Business funding journal could not be posted. Check the required accounting accounts.');
            $row->update(['journal_entry_id' => $journal->id]);
            $this->audit->record(action: 'POST_BUSINESS_FUNDING', module: 'cash-management', entityType: BusinessFundingTransaction::class, entityId: $row->id, after: $row->fresh()->toArray(), actor: $actor);
            return $row->fresh(['branch','cashAccount']);
        });
    }

    public function reverse(User $actor, BusinessFundingTransaction $row, string $reason): BusinessFundingTransaction
    {
        if ((int) $row->business_id !== (int) $actor->business_id) abort(404);
        if ($row->status !== 'POSTED') throw new DomainException('Only posted business funding can be reversed.');
        $reason = trim($reason); if ($reason === '') throw new DomainException('Reversal reason is required.');
        return DB::transaction(function () use ($actor, $row, $reason) {
            $locked = BusinessFundingTransaction::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'POSTED') throw new DomainException('This funding transaction is no longer available for reversal.');
            $creditKey = $locked->funding_type === 'CAPITAL_CONTRIBUTION' ? 'OWNERS_CAPITAL' : 'LOANS_PAYABLE_OWNER';
            $amount = round((float) $locked->amount, 2); $now = $this->clock->now();
            $journal = $this->journals->postEntry(
                $locked->business_id, $locked->branch_id, $now->toDateString(), 'BUSINESS_FUNDING', $locked->id, 'REVERSE', $locked->code,
                'Reverse business funding '.$locked->code.': '.$reason,
                [
                    ['account_key' => $creditKey, 'debit' => $amount, 'credit' => 0, 'memo' => 'Reverse owner funding source'],
                    ['account_id' => $locked->cash_account_id, 'debit' => 0, 'credit' => $amount, 'memo' => 'Reverse cash received'],
                ], $actor->id,
            );
            if (! $journal) throw new DomainException('Business funding reversal journal could not be posted.');
            $before = $locked->toArray();
            $locked->update(['status'=>'REVERSED','reversal_journal_entry_id'=>$journal->id,'reversed_by'=>$actor->id,'reversed_at'=>$now,'reversal_reason'=>$reason]);
            $this->audit->record(action: 'REVERSE_BUSINESS_FUNDING', module: 'cash-management', entityType: BusinessFundingTransaction::class, entityId: $locked->id, before: $before, after: $locked->fresh()->toArray(), reason: $reason, actor: $actor);
            return $locked->fresh(['branch','cashAccount']);
        });
    }

    private function serialize(BusinessFundingTransaction $row, ?ReversalApprovalRequest $pendingReversal = null): array
    {
        return [
            'id'=>$row->id,'code'=>$row->code,'funding_date'=>$row->funding_date?->toDateString(),'funding_type'=>$row->funding_type,
            'branch'=>$row->branch?['id'=>$row->branch->id,'code'=>$row->branch->code,'name'=>$row->branch->name]:null,
            'cash_account'=>$row->cashAccount?['id'=>$row->cashAccount->id,'code'=>$row->cashAccount->code,'name'=>$row->cashAccount->name]:null,
            'amount'=>(float)$row->amount,'reference'=>$row->reference,'notes'=>$row->notes,'status'=>$row->status,
            'posted_at'=>$row->posted_at?->toIso8601String(),'reversed_at'=>$row->reversed_at?->toIso8601String(),'reversal_reason'=>$row->reversal_reason,
            'pending_reversal'=>ReversalApprovalService::serialize($pendingReversal),
        ];
    }
}
