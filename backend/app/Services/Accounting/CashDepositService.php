<?php

namespace App\Services\Accounting;

use App\Models\Branch;
use App\Models\CashDeposit;
use App\Models\ReversalApprovalRequest;
use App\Models\CashRemittance;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Services\Foundation\BusinessClock;
use App\Services\Foundation\CodeSequenceService;
use App\Services\Security\AuditService;
use DomainException;
use Illuminate\Support\Facades\DB;

class CashDepositService
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
        $query = CashDeposit::query()
            ->where('business_id', $actor->business_id)
            ->with(['branch', 'sourceCashAccount', 'bankAccount', 'cashRemittance.receivedByUser', 'creator'])
            ->orderByDesc('deposit_date')->orderByDesc('id');

        if (! empty($filters['branch_id'])) $query->where('branch_id', (int) $filters['branch_id']);
        if (! empty($filters['from'])) $query->whereDate('deposit_date', '>=', $filters['from']);
        if (! empty($filters['to'])) $query->whereDate('deposit_date', '<=', $filters['to']);

        $rows = $query->get();
        $posted = $rows->where('status', 'POSTED');
        $pending = ReversalApprovalRequest::query()->where('business_id',$actor->business_id)->where('source_type','CASH_DEPOSIT')->whereIn('source_id',$rows->pluck('id'))->where('status','PENDING')->with(['requester','reviewer'])->get()->keyBy('source_id');

        return [
            'rows' => $rows->map(fn (CashDeposit $row) => $this->serialize($row, $pending->get($row->id)))->values(),
            'summary' => [
                'deposited' => round((float) $posted->sum('amount'), 2),
                'direct_deposited' => round((float) $posted->where('deposit_source', 'DIRECT')->sum('amount'), 2),
                'remitted_deposited' => round((float) $posted->where('deposit_source', 'REMITTANCE')->sum('amount'), 2),
                'transactions' => $posted->count(),
            ],
            'options' => $this->options($actor),
        ];
    }

    public function options(User $actor): array
    {
        $accounts = ChartOfAccount::query()
            ->where('business_id', $actor->business_id)
            ->where('active', true)
            ->where('posting_type', 'POSTING')
            ->where('is_cash_account', true)
            ->orderBy('code')->get();

        $cash = $this->handling->cashOnHandAccount($actor);
        $availableRemittances = CashRemittance::query()
            ->where('business_id', $actor->business_id)
            ->where('status', 'POSTED')
            ->with(['branch', 'receivedByUser', 'deposits' => fn ($query) => $query->where('status', 'POSTED')])
            ->orderByDesc('business_date')->orderByDesc('id')->get()
            ->map(function (CashRemittance $row) {
                $deposited = round((float) $row->deposits->sum('amount'), 2);
                $remaining = max(0, round((float) $row->amount - $deposited, 2));
                if ($remaining <= 0.009) return null;
                return [
                    'id' => $row->id,
                    'code' => $row->code,
                    'business_date' => $row->business_date?->toDateString(),
                    'branch_id' => $row->branch_id,
                    'branch_name' => $row->branch?->name,
                    'received_by' => $row->received_by_name_snapshot ?: ($row->receivedByUser?->display_name ?: $row->receivedByUser?->name),
                    'amount' => (float) $row->amount,
                    'remaining_amount' => $remaining,
                ];
            })->filter()->values();

        return [
            'branches' => Branch::query()->where('business_id', $actor->business_id)->where('status', 'ACTIVE')->orderBy('name')->get(['id','code','name']),
            'source_accounts' => [$this->accountPayload($cash)],
            'bank_accounts' => $accounts->filter(fn ($a) => ! in_array($a->system_key, ['CASH_ON_HAND','PETTY_CASH','CASH_WITH_MANAGER'], true))->map(fn ($a) => $this->accountPayload($a))->values(),
            'available_remittances' => $availableRemittances,
        ];
    }

    public function daySummary(User $actor, int $branchId, string $businessDate, int $sourceAccountId, string $depositSource = 'DIRECT', ?int $cashRemittanceId = null): array
    {
        $depositSource = strtoupper(trim($depositSource ?: 'DIRECT'));
        if ($depositSource === 'REMITTANCE') {
            $remittance = $this->remittance($actor, $cashRemittanceId);
            if ((int) $remittance->branch_id !== $branchId) throw new DomainException('Selected remittance does not belong to the selected branch.');
            $deposited = round((float) $remittance->deposits()->where('status', 'POSTED')->sum('amount'), 2);
            $remaining = max(0, round((float) $remittance->amount - $deposited, 2));
            return [
                'expected_cash_sales' => (float) $remittance->expected_cash_sales_snapshot,
                'deposited' => $deposited,
                'remaining_to_deposit' => $remaining,
                'remittance_amount' => (float) $remittance->amount,
                'remittance_code' => $remittance->code,
            ];
        }

        $this->branch($actor, $branchId);
        $source = $this->cashAccount($actor, $sourceAccountId);
        if ($source->system_key !== 'CASH_ON_HAND' && ! str_contains(strtoupper($source->name), 'CASH ON HAND')) {
            throw new DomainException('Direct bank deposits must originate from a Cash on Hand account.');
        }

        $status = $this->handling->dayStatus($actor, $branchId, $businessDate);
        return [
            ...$status,
            'deposited' => $status['deposited_direct'],
            'remaining_to_deposit' => $status['remaining_at_cashier'],
        ];
    }

    public function create(User $actor, array $data): CashDeposit
    {
        $depositSource = strtoupper(trim((string) ($data['deposit_source'] ?? 'DIRECT')));
        if (! in_array($depositSource, ['DIRECT','REMITTANCE'], true)) throw new DomainException('Deposit source must be Direct Cash or Remitted Cash.');

        $bank = $this->cashAccount($actor, (int) $data['bank_account_id']);
        if (in_array($bank->system_key, ['CASH_ON_HAND','PETTY_CASH','CASH_WITH_MANAGER'], true)) {
            throw new DomainException('Deposit destination must be a bank or e-wallet cash account.');
        }

        $amount = round((float) $data['amount'], 2);
        if ($amount <= 0) throw new DomainException('Deposit amount must be greater than zero.');

        $remittance = null;
        if ($depositSource === 'REMITTANCE') {
            $remittance = $this->remittance($actor, isset($data['cash_remittance_id']) ? (int) $data['cash_remittance_id'] : null);
            $branch = $this->branch($actor, (int) $remittance->branch_id);
            $source = $this->handling->custodyAccount($actor);
            $businessDate = $remittance->business_date?->toDateString();
            $summary = $this->daySummary($actor, $branch->id, $businessDate, $source->id, 'REMITTANCE', $remittance->id);
            if ($amount - (float) $summary['remaining_to_deposit'] > 0.009) {
                throw new DomainException('Deposit amount exceeds the remaining cash held under the selected remittance.');
            }
        } else {
            $branch = $this->branch($actor, (int) $data['branch_id']);
            $source = $this->cashAccount($actor, (int) $data['source_cash_account_id']);
            if ($source->id === $bank->id) throw new DomainException('Source cash and destination bank account must be different.');
            if ($source->system_key !== 'CASH_ON_HAND' && ! str_contains(strtoupper($source->name), 'CASH ON HAND')) throw new DomainException('Direct deposit source account must be Cash on Hand.');
            $businessDate = $data['business_date'];
            $summary = $this->daySummary($actor, $branch->id, $businessDate, $source->id, 'DIRECT');
            if ($amount - (float) $summary['remaining_to_deposit'] > 0.009) {
                throw new DomainException('Deposit amount exceeds the remaining unhandled cash sales for the selected business date.');
            }
        }

        return DB::transaction(function () use ($actor, $data, $branch, $source, $bank, $amount, $summary, $depositSource, $remittance, $businessDate) {
            $now = $this->clock->now();
            $row = CashDeposit::query()->create([
                'business_id' => $actor->business_id,
                'branch_id' => $branch->id,
                'code' => $this->codes->next('CASH_DEPOSIT:'.$now->format('my'), 'DEP-'.$now->format('my').'-', 5, $actor->business_id),
                'business_date' => $businessDate,
                'deposit_date' => $data['deposit_date'],
                'deposit_source' => $depositSource,
                'cash_remittance_id' => $remittance?->id,
                'source_cash_account_id' => $source->id,
                'bank_account_id' => $bank->id,
                'expected_cash_sales_snapshot' => $summary['expected_cash_sales'],
                'amount' => $amount,
                'reference' => trim((string) ($data['reference'] ?? '')) ?: null,
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                'status' => 'POSTED',
                'created_by' => $actor->id,
                'posted_at' => $now,
            ]);

            $sourceLabel = $depositSource === 'REMITTANCE' ? 'remitted cash '.$remittance?->code : 'cash sales';
            $journal = $this->journals->postEntry(
                $actor->business_id, $branch->id, $data['deposit_date'], 'CASH_DEPOSIT', $row->id, 'POST', $row->code,
                'Deposit '.$sourceLabel.' '.$row->code,
                [
                    ['account_id' => $bank->id, 'debit' => $amount, 'credit' => 0, 'memo' => 'Cash deposited to '.$bank->name],
                    ['account_id' => $source->id, 'debit' => 0, 'credit' => $amount, 'memo' => 'Transfer from '.$source->name],
                ],
                $actor->id,
            );
            if (! $journal) throw new DomainException('Cash deposit accounting journal could not be posted. Check the selected accounts.');
            $row->update(['journal_entry_id' => $journal->id]);

            $this->audit->record(action: 'POST_CASH_DEPOSIT', module: 'cash-deposits', entityType: CashDeposit::class, entityId: $row->id, after: $row->fresh()->toArray(), actor: $actor);
            return $row->fresh(['branch', 'sourceCashAccount', 'bankAccount', 'cashRemittance.receivedByUser']);
        });
    }

    public function reverse(User $actor, CashDeposit $deposit, string $reason): CashDeposit
    {
        $this->guardBusiness($actor, $deposit);
        if ($deposit->status !== 'POSTED') throw new DomainException('Only a posted cash deposit can be reversed.');
        $reason = trim($reason);
        if ($reason === '') throw new DomainException('Reversal reason is required.');

        return DB::transaction(function () use ($actor, $deposit, $reason) {
            $locked = CashDeposit::query()->whereKey($deposit->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'POSTED') throw new DomainException('This cash deposit is no longer available for reversal.');
            $locked->loadMissing(['sourceCashAccount', 'bankAccount']);
            $amount = round((float) $locked->amount, 2);
            $now = $this->clock->now();
            $journal = $this->journals->postEntry(
                $locked->business_id, $locked->branch_id, $now->toDateString(), 'CASH_DEPOSIT', $locked->id, 'REVERSE', $locked->code,
                'Reverse cash deposit '.$locked->code.': '.$reason,
                [
                    ['account_id' => $locked->source_cash_account_id, 'debit' => $amount, 'credit' => 0, 'memo' => 'Restore cash to '.$locked->sourceCashAccount?->name],
                    ['account_id' => $locked->bank_account_id, 'debit' => 0, 'credit' => $amount, 'memo' => 'Reverse bank deposit'],
                ],
                $actor->id,
            );
            if (! $journal) throw new DomainException('Cash deposit reversal journal could not be posted.');
            $before = $locked->toArray();
            $locked->update(['status' => 'REVERSED', 'reversal_journal_entry_id' => $journal->id, 'reversed_by' => $actor->id, 'reversed_at' => $now, 'reversal_reason' => $reason]);
            $this->audit->record(action: 'REVERSE_CASH_DEPOSIT', module: 'cash-deposits', entityType: CashDeposit::class, entityId: $locked->id, before: $before, after: $locked->fresh()->toArray(), reason: $reason, actor: $actor);
            return $locked->fresh(['branch', 'sourceCashAccount', 'bankAccount', 'cashRemittance.receivedByUser', 'creator']);
        });
    }

    private function branch(User $actor, int $id): Branch
    {
        return Branch::query()->where('business_id', $actor->business_id)->whereKey($id)->firstOrFail();
    }

    private function cashAccount(User $actor, int $id): ChartOfAccount
    {
        $account = ChartOfAccount::query()->where('business_id', $actor->business_id)->whereKey($id)->where('active', true)->where('posting_type', 'POSTING')->where('is_cash_account', true)->first();
        if (! $account) throw new DomainException('Select an active Posting cash/payment account.');
        return $account;
    }

    private function remittance(User $actor, ?int $id): CashRemittance
    {
        if (! $id) throw new DomainException('Select a posted cash remittance to deposit.');
        $row = CashRemittance::query()->where('business_id', $actor->business_id)->whereKey($id)->where('status', 'POSTED')->first();
        if (! $row) throw new DomainException('Selected cash remittance is unavailable.');
        return $row;
    }

    private function guardBusiness(User $actor, CashDeposit $deposit): void
    {
        if ((int) $deposit->business_id !== (int) $actor->business_id) abort(404);
    }

    private function accountPayload(ChartOfAccount $account): array
    {
        return ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'system_key' => $account->system_key];
    }

    private function serialize(CashDeposit $row, ?ReversalApprovalRequest $pendingReversal = null): array
    {
        $remittanceName = $row->cashRemittance?->received_by_name_snapshot ?: ($row->cashRemittance?->receivedByUser?->display_name ?: $row->cashRemittance?->receivedByUser?->name);
        $depositorName = $row->creator?->display_name ?: $row->creator?->name ?: $row->creator?->username;
        return [
            'id' => $row->id, 'code' => $row->code,
            'business_date' => $row->business_date?->toDateString(), 'deposit_date' => $row->deposit_date?->toDateString(),
            'deposit_source' => $row->deposit_source ?: ($row->cash_remittance_id ? 'REMITTANCE' : 'DIRECT'),
            'cash_remittance' => $row->cashRemittance ? ['id' => $row->cashRemittance->id, 'code' => $row->cashRemittance->code, 'received_by' => $remittanceName] : null,
            'branch' => $row->branch ? ['id' => $row->branch->id, 'name' => $row->branch->name, 'code' => $row->branch->code] : null,
            'source_account' => $row->sourceCashAccount ? $this->accountPayload($row->sourceCashAccount) : null,
            'bank_account' => $row->bankAccount ? $this->accountPayload($row->bankAccount) : null,
            'expected_cash_sales_snapshot' => (float) $row->expected_cash_sales_snapshot,
            'amount' => (float) $row->amount, 'reference' => $row->reference, 'notes' => $row->notes,
            'deposited_by' => $depositorName, 'deposited_by_user_id' => $row->created_by,
            'status' => $row->status, 'posted_at' => $row->posted_at?->toIso8601String(),
            'reversed_at' => $row->reversed_at?->toIso8601String(), 'reversal_reason' => $row->reversal_reason,
            'pending_reversal' => ReversalApprovalService::serialize($pendingReversal),
        ];
    }
}
