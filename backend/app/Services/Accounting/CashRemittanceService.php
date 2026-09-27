<?php

namespace App\Services\Accounting;

use App\Models\Branch;
use App\Models\CashRemittance;
use App\Models\ReversalApprovalRequest;
use App\Models\User;
use App\Services\Foundation\BusinessClock;
use App\Services\Foundation\CodeSequenceService;
use App\Services\Security\AuditService;
use DomainException;
use Illuminate\Support\Facades\DB;

class CashRemittanceService
{
    public function __construct(
        private readonly AccountingJournalService $journals,
        private readonly CashHandlingService $handling,
        private readonly CodeSequenceService $codes,
        private readonly BusinessClock $clock,
        private readonly AuditService $audit,
    ) {}

    public function list(User $actor, array $filters = []): array
    {
        $rows = CashRemittance::query()
            ->where('business_id', $actor->business_id)
            ->with(['branch', 'sourceCashAccount', 'custodyCashAccount', 'receivedByUser', 'creator', 'deposits' => fn ($query) => $query->where('status', 'POSTED')])
            ->when(! empty($filters['branch_id']), fn ($query) => $query->where('branch_id', (int) $filters['branch_id']))
            ->when(! empty($filters['from']), fn ($query) => $query->whereDate('remittance_date', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn ($query) => $query->whereDate('remittance_date', '<=', $filters['to']))
            ->orderByDesc('remittance_date')->orderByDesc('id')->get();

        $posted = $rows->where('status', 'POSTED');
        $deposited = $posted->sum(fn (CashRemittance $row) => $row->deposits->sum('amount'));
        $pending = ReversalApprovalRequest::query()->where('business_id',$actor->business_id)->where('source_type','CASH_REMITTANCE')->whereIn('source_id',$rows->pluck('id'))->where('status','PENDING')->with(['requester','reviewer'])->get()->keyBy('source_id');

        return [
            'rows' => $rows->map(fn (CashRemittance $row) => $this->serialize($row, $pending->get($row->id)))->values(),
            'summary' => [
                'remitted' => round((float) $posted->sum('amount'), 2),
                'deposited_from_remittance' => round((float) $deposited, 2),
                'still_with_manager' => round((float) $posted->sum('amount') - (float) $deposited, 2),
                'transactions' => $posted->count(),
            ],
            'options' => $this->options($actor),
        ];
    }

    public function options(User $actor): array
    {
        $cash = $this->handling->cashOnHandAccount($actor);
        $custody = $this->handling->custodyAccount($actor);
        $recipients = User::query()
            ->where('business_id', $actor->business_id)
            ->where('status', 'ACTIVE')
            ->whereHas('role', fn ($query) => $query->where('code', 'ROLE-MGR')->orWhere('is_super_admin', true))
            ->with('role:id,code,name')
            ->orderBy('display_name')->orderBy('name')->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->display_name ?: $user->name ?: $user->username,
                'role' => $user->role?->name,
            ])->values();

        return [
            'branches' => Branch::query()->where('business_id', $actor->business_id)->where('status', 'ACTIVE')->orderBy('name')->get(['id','code','name']),
            'source_accounts' => [['id' => $cash->id, 'code' => $cash->code, 'name' => $cash->name, 'system_key' => $cash->system_key]],
            'custody_account' => ['id' => $custody->id, 'code' => $custody->code, 'name' => $custody->name, 'system_key' => $custody->system_key],
            'recipients' => $recipients,
        ];
    }

    public function daySummary(User $actor, int $branchId, string $businessDate): array
    {
        $this->branch($actor, $branchId);
        $status = $this->handling->dayStatus($actor, $branchId, $businessDate);
        return [
            ...$status,
            'remaining_to_remit' => $status['remaining_at_cashier'],
        ];
    }

    public function create(User $actor, array $data): CashRemittance
    {
        $branch = $this->branch($actor, (int) $data['branch_id']);
        $source = $this->handling->cashOnHandAccount($actor);
        $custody = $this->handling->custodyAccount($actor);
        $recipient = $this->recipient($actor, (int) $data['received_by_user_id']);
        $amount = round((float) $data['amount'], 2);
        if ($amount <= 0) throw new DomainException('Remittance amount must be greater than zero.');

        $summary = $this->daySummary($actor, $branch->id, $data['business_date']);
        if ($amount - (float) $summary['remaining_to_remit'] > 0.009) {
            throw new DomainException('Remittance amount exceeds the remaining unhandled cash sales for the selected business date.');
        }

        return DB::transaction(function () use ($actor, $data, $branch, $source, $custody, $recipient, $amount, $summary) {
            $now = $this->clock->now();
            $recipientName = $recipient->display_name ?: $recipient->name ?: $recipient->username;
            $row = CashRemittance::query()->create([
                'business_id' => $actor->business_id,
                'branch_id' => $branch->id,
                'code' => $this->codes->next('CASH_REMITTANCE:'.$now->format('my'), 'REM-'.$now->format('my').'-', 5, $actor->business_id),
                'business_date' => $data['business_date'],
                'remittance_date' => $data['remittance_date'],
                'source_cash_account_id' => $source->id,
                'custody_cash_account_id' => $custody->id,
                'received_by_user_id' => $recipient->id,
                'received_by_name_snapshot' => $recipientName,
                'expected_cash_sales_snapshot' => $summary['expected_cash_sales'],
                'amount' => $amount,
                'reference' => trim((string) ($data['reference'] ?? '')) ?: null,
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                'status' => 'POSTED',
                'created_by' => $actor->id,
                'posted_at' => $now,
            ]);

            $journal = $this->journals->postEntry(
                $actor->business_id, $branch->id, $data['remittance_date'], 'CASH_REMITTANCE', $row->id, 'POST', $row->code,
                'Remit cash sales '.$row->code.' to '.$recipientName,
                [
                    ['account_id' => $custody->id, 'debit' => $amount, 'credit' => 0, 'memo' => 'Cash received by '.$recipientName.' for deposit'],
                    ['account_id' => $source->id, 'debit' => 0, 'credit' => $amount, 'memo' => 'Cash remitted from '.$source->name],
                ],
                $actor->id,
            );
            if (! $journal) throw new DomainException('Cash remittance accounting journal could not be posted. Check the cash handling accounts.');
            $row->update(['journal_entry_id' => $journal->id]);

            $this->audit->record(action: 'POST_CASH_REMITTANCE', module: 'cash-deposits', entityType: CashRemittance::class, entityId: $row->id, after: $row->fresh()->toArray(), actor: $actor);
            return $row->fresh(['branch', 'sourceCashAccount', 'custodyCashAccount', 'receivedByUser', 'creator', 'deposits']);
        });
    }

    public function reverse(User $actor, CashRemittance $remittance, string $reason): CashRemittance
    {
        $this->guardBusiness($actor, $remittance);
        if ($remittance->status !== 'POSTED') throw new DomainException('Only a posted cash remittance can be reversed.');
        $reason = trim($reason);
        if ($reason === '') throw new DomainException('Reversal reason is required.');

        return DB::transaction(function () use ($actor, $remittance, $reason) {
            $locked = CashRemittance::query()->whereKey($remittance->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'POSTED') throw new DomainException('This cash remittance is no longer available for reversal.');
            if ($locked->deposits()->where('status', 'POSTED')->exists()) {
                throw new DomainException('Reverse the related bank deposit first before reversing this remittance.');
            }
            $locked->loadMissing(['sourceCashAccount', 'custodyCashAccount']);
            $amount = round((float) $locked->amount, 2);
            $now = $this->clock->now();
            $journal = $this->journals->postEntry(
                $locked->business_id, $locked->branch_id, $now->toDateString(), 'CASH_REMITTANCE', $locked->id, 'REVERSE', $locked->code,
                'Reverse cash remittance '.$locked->code.': '.$reason,
                [
                    ['account_id' => $locked->source_cash_account_id, 'debit' => $amount, 'credit' => 0, 'memo' => 'Restore cash to Cash on Hand'],
                    ['account_id' => $locked->custody_cash_account_id, 'debit' => 0, 'credit' => $amount, 'memo' => 'Reverse manager cash custody'],
                ],
                $actor->id,
            );
            if (! $journal) throw new DomainException('Cash remittance reversal journal could not be posted.');
            $before = $locked->toArray();
            $locked->update(['status' => 'REVERSED', 'reversal_journal_entry_id' => $journal->id, 'reversed_by' => $actor->id, 'reversed_at' => $now, 'reversal_reason' => $reason]);
            $this->audit->record(action: 'REVERSE_CASH_REMITTANCE', module: 'cash-deposits', entityType: CashRemittance::class, entityId: $locked->id, before: $before, after: $locked->fresh()->toArray(), reason: $reason, actor: $actor);
            return $locked->fresh(['branch', 'sourceCashAccount', 'custodyCashAccount', 'receivedByUser', 'creator', 'deposits']);
        });
    }

    private function branch(User $actor, int $id): Branch
    {
        return Branch::query()->where('business_id', $actor->business_id)->whereKey($id)->firstOrFail();
    }

    private function recipient(User $actor, int $id): User
    {
        $user = User::query()
            ->where('business_id', $actor->business_id)
            ->where('status', 'ACTIVE')
            ->whereKey($id)
            ->whereHas('role', fn ($query) => $query->where('code', 'ROLE-MGR')->orWhere('is_super_admin', true))
            ->first();
        if (! $user) throw new DomainException('Select an active Manager or Super Admin as the cash recipient.');
        return $user;
    }

    private function guardBusiness(User $actor, CashRemittance $remittance): void
    {
        if ((int) $remittance->business_id !== (int) $actor->business_id) abort(404);
    }

    private function serialize(CashRemittance $row, ?ReversalApprovalRequest $pendingReversal = null): array
    {
        $deposited = round((float) $row->deposits->where('status', 'POSTED')->sum('amount'), 2);
        $remaining = max(0, round((float) $row->amount - $deposited, 2));
        $handlingStatus = $row->status === 'REVERSED' ? 'REVERSED' : ($remaining <= 0.009 ? 'DEPOSITED' : ($deposited > 0.009 ? 'PARTIALLY_DEPOSITED' : 'REMITTED'));
        $recipientName = $row->received_by_name_snapshot ?: ($row->receivedByUser?->display_name ?: $row->receivedByUser?->name);
        $remitterName = $row->creator?->display_name ?: $row->creator?->name ?: $row->creator?->username;

        return [
            'id' => $row->id,
            'code' => $row->code,
            'business_date' => $row->business_date?->toDateString(),
            'remittance_date' => $row->remittance_date?->toDateString(),
            'branch' => $row->branch ? ['id' => $row->branch->id, 'name' => $row->branch->name, 'code' => $row->branch->code] : null,
            'source_account' => $row->sourceCashAccount ? ['id' => $row->sourceCashAccount->id, 'code' => $row->sourceCashAccount->code, 'name' => $row->sourceCashAccount->name] : null,
            'custody_account' => $row->custodyCashAccount ? ['id' => $row->custodyCashAccount->id, 'code' => $row->custodyCashAccount->code, 'name' => $row->custodyCashAccount->name] : null,
            'received_by' => $recipientName,
            'received_by_user_id' => $row->received_by_user_id,
            'remitted_by' => $remitterName,
            'remitted_by_user_id' => $row->created_by,
            'expected_cash_sales_snapshot' => (float) $row->expected_cash_sales_snapshot,
            'amount' => (float) $row->amount,
            'deposited_amount' => $deposited,
            'remaining_amount' => $remaining,
            'reference' => $row->reference,
            'notes' => $row->notes,
            'status' => $row->status,
            'handling_status' => $handlingStatus,
            'posted_at' => $row->posted_at?->toIso8601String(),
            'reversed_at' => $row->reversed_at?->toIso8601String(),
            'reversal_reason' => $row->reversal_reason,
            'pending_reversal' => ReversalApprovalService::serialize($pendingReversal),
        ];
    }
}
