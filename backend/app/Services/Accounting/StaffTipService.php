<?php

namespace App\Services\Accounting;

use App\Models\ChartOfAccount;
use App\Models\StaffTip;
use App\Models\User;
use App\Services\Foundation\BusinessClock;
use App\Services\Security\AuditService;
use DomainException;
use Illuminate\Support\Facades\DB;

class StaffTipService
{
    public function __construct(
        private readonly AccountingJournalService $journals,
        private readonly BusinessClock $clock,
        private readonly AuditService $audit,
    ) {}

    public function list(User $actor, array $filters = []): array
    {
        $query = StaffTip::query()
            ->where('business_id', $actor->business_id)
            ->with(['branch', 'customer', 'personnel', 'payment', 'receivedCashAccount', 'payoutAccount', 'paidByUser'])
            ->orderByDesc('id');

        if (! empty($filters['branch_id'])) $query->where('branch_id', (int) $filters['branch_id']);
        if (! empty($filters['from'])) $query->whereDate('created_at', '>=', $filters['from']);
        if (! empty($filters['to'])) $query->whereDate('created_at', '<=', $filters['to']);
        if (! empty($filters['status'])) $query->where('status', strtoupper((string) $filters['status']));

        $rows = $query->limit(250)->get();
        $pending = round((float) $rows->where('status', 'PENDING')->sum('amount'), 2);
        $paid = round((float) $rows->where('status', 'PAID')->sum('amount'), 2);

        return [
            'rows' => $rows->map(fn (StaffTip $tip) => $this->serialize($tip))->values(),
            'summary' => [
                'pending_amount' => $pending,
                'paid_amount' => $paid,
                'pending_count' => $rows->where('status', 'PENDING')->count(),
            ],
            'options' => [
                'payout_accounts' => $this->payoutAccounts($actor),
            ],
        ];
    }

    public function payout(User $actor, StaffTip $tip, array $data): StaffTip
    {
        if ((int) $tip->business_id !== (int) $actor->business_id) abort(404);

        return DB::transaction(function () use ($actor, $tip, $data) {
            $locked = StaffTip::query()->whereKey($tip->id)->lockForUpdate()->firstOrFail();
            if ((int) $locked->business_id !== (int) $actor->business_id) abort(404);
            if (strtoupper((string) $locked->status) !== 'PENDING') {
                throw new DomainException('Only pending staff tips can be released.');
            }

            $account = $this->resolvePayoutAccount($actor, (int) ($data['payout_account_id'] ?? 0));
            $payoutDate = (string) ($data['payout_date'] ?? $this->clock->now()->toDateString());
            $reference = trim((string) ($data['payout_reference'] ?? '')) ?: null;
            $amount = round((float) $locked->amount, 2);
            if ($amount <= 0.004) throw new DomainException('Tip amount must be greater than zero.');

            $journal = $this->journals->postEntry(
                (int) $locked->business_id,
                $locked->branch_id,
                $payoutDate,
                'STAFF_TIP',
                (int) $locked->id,
                'PAYOUT',
                $locked->code,
                'Release staff tip '.$locked->code,
                [
                    ['account_key' => 'STAFF_TIPS_PAYABLE', 'debit' => $amount, 'credit' => 0, 'memo' => 'Release tip payable to staff'],
                    ['account_id' => $account->id, 'debit' => 0, 'credit' => $amount, 'memo' => 'Tip payout from '.$account->name],
                ],
                $actor->id,
            );
            if (! $journal) throw new DomainException('Tip payout journal could not be posted. Check Staff Tips Payable and payout account setup.');

            $before = $locked->toArray();
            $locked->update([
                'status' => 'PAID',
                'payout_account_id' => $account->id,
                'payout_method_snapshot' => $account->name,
                'payout_reference' => $reference,
                'payout_date' => $payoutDate,
                'paid_by' => $actor->id,
                'paid_at' => $this->clock->now(),
            ]);

            $this->audit->record(
                action: 'PAY_STAFF_TIP',
                module: 'cash-deposits',
                entityType: StaffTip::class,
                entityId: $locked->id,
                before: $before,
                after: $locked->fresh()->toArray(),
                actor: $actor,
            );

            return $locked->fresh(['branch', 'customer', 'personnel', 'payment', 'receivedCashAccount', 'payoutAccount', 'paidByUser']);
        });
    }

    private function payoutAccounts(User $actor): array
    {
        return ChartOfAccount::query()
            ->where('business_id', $actor->business_id)
            ->where('active', true)
            ->where('posting_type', 'POSTING')
            ->where('is_cash_account', true)
            ->where(function ($q) {
                $q->where('system_key', 'PETTY_CASH')
                    ->orWhere('is_funding_source', true);
            })
            ->whereNotIn('system_key', ['CASH_ON_HAND', 'CASH_WITH_MANAGER', 'POS_CLEARING'])
            ->orderBy('code')
            ->get()
            ->map(fn (ChartOfAccount $account) => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'system_key' => $account->system_key,
            ])->values()->all();
    }

    private function resolvePayoutAccount(User $actor, int $accountId): ChartOfAccount
    {
        if ($accountId <= 0) throw new DomainException('Select a payout source.');

        $account = ChartOfAccount::query()
            ->where('business_id', $actor->business_id)
            ->whereKey($accountId)
            ->where('active', true)
            ->where('posting_type', 'POSTING')
            ->where('is_cash_account', true)
            ->first();

        if (! $account) throw new DomainException('Select an active Posting cash, bank, e-wallet, or Petty Cash account.');
        if (in_array(strtoupper((string) $account->system_key), ['CASH_ON_HAND', 'CASH_WITH_MANAGER', 'POS_CLEARING'], true)) {
            throw new DomainException('Staff tips cannot be released from Cash on Hand, Cash with Manager, or a clearing account. Use Petty Cash or an approved bank/e-wallet fund source.');
        }
        if (strtoupper((string) $account->system_key) !== 'PETTY_CASH' && ! $account->is_funding_source) {
            throw new DomainException('Select Petty Cash or an approved Operating Fund Source for the staff tip payout.');
        }

        return $account;
    }

    private function serialize(StaffTip $tip): array
    {
        return [
            'id' => $tip->id,
            'code' => $tip->code,
            'sale_id' => $tip->sale_id,
            'booking_id' => $tip->booking_id,
            'branch' => $tip->branch ? ['id' => $tip->branch->id, 'code' => $tip->branch->code, 'name' => $tip->branch->name] : null,
            'customer' => $tip->customer ? ['id' => $tip->customer->id, 'name' => trim($tip->customer->first_name.' '.$tip->customer->last_name)] : null,
            'personnel' => $tip->personnel ? ['id' => $tip->personnel->id, 'code' => $tip->personnel->code, 'name' => $tip->personnel->full_name] : null,
            'amount' => (float) $tip->amount,
            'received_method' => $tip->received_method_snapshot,
            'received_reference' => $tip->received_reference,
            'received_account' => $tip->receivedCashAccount ? ['id' => $tip->receivedCashAccount->id, 'code' => $tip->receivedCashAccount->code, 'name' => $tip->receivedCashAccount->name] : null,
            'status' => $tip->status,
            'payout_account' => $tip->payoutAccount ? ['id' => $tip->payoutAccount->id, 'code' => $tip->payoutAccount->code, 'name' => $tip->payoutAccount->name] : null,
            'payout_method' => $tip->payout_method_snapshot,
            'payout_reference' => $tip->payout_reference,
            'payout_date' => $tip->payout_date?->toDateString(),
            'paid_by' => $tip->paidByUser?->display_name ?: $tip->paidByUser?->name ?: $tip->paidByUser?->username,
            'paid_at' => $tip->paid_at?->toIso8601String(),
            'created_at' => $tip->created_at?->toIso8601String(),
        ];
    }
}
