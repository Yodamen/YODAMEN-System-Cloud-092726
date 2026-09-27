<?php

namespace App\Services\Accounting;

use App\Models\AccountingJournalLine;
use App\Models\ChartOfAccount;
use App\Models\Personnel;
use App\Models\ProfitSharingRun;
use App\Models\User;
use App\Services\Foundation\BusinessClock;
use App\Services\Foundation\CodeSequenceService;
use DomainException;
use Illuminate\Support\Facades\DB;

class ProfitSharingService
{
    public function __construct(
        private readonly AccountingJournalService $journals,
        private readonly CodeSequenceService $codes,
        private readonly BusinessClock $clock,
    ) {}

    public function createDraft(User $actor, array $data): ProfitSharingRun
    {
        return DB::transaction(function () use ($actor, $data) {
            $run = ProfitSharingRun::query()->create([
                'business_id' => $actor->business_id,
                'branch_id' => $data['branch_id'] ?? null,
                'code' => $this->codes->next('PROFIT_SHARING:'.$this->clock->now()->format('my'), 'PSR-'.$this->clock->now()->format('my').'-', 5, $actor->business_id),
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'basis' => strtoupper($data['basis'] ?? 'ACCRUAL'),
                'cost_basis' => strtoupper($data['cost_basis'] ?? 'AUDIT_ADJUSTED'),
                'notes' => $data['notes'] ?? null,
                'status' => 'DRAFT',
                'created_by' => $actor->id,
            ]);
            return $this->recalculate($actor, $run, $data['distributable_profit'] ?? null);
        });
    }

    public function recalculate(User $actor, ProfitSharingRun $run, mixed $distributableOverride = null): ProfitSharingRun
    {
        abort_unless((int) $run->business_id === (int) $actor->business_id, 404);
        if ($run->status !== 'DRAFT') throw new DomainException('Only Draft profit sharing runs can be recalculated.');

        return DB::transaction(function () use ($run, $distributableOverride) {
            $run = ProfitSharingRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $netIncome = $this->netIncome(
                (int) $run->business_id,
                $run->period_start->toDateString(),
                $run->period_end->toDateString(),
                $run->branch_id,
                $run->basis,
                $run->cost_basis,
            );
            $distributable = $distributableOverride === null || $distributableOverride === ''
                ? max(0, $netIncome)
                : max(0, round((float) $distributableOverride, 2));

            $partners = Personnel::query()
                ->where('business_id', $run->business_id)
                ->where('profit_sharing_eligible', true)
                ->where('status', 'ACTIVE')
                ->whereDate('profit_sharing_effective_from', '<=', $run->period_end)
                ->where(function ($q) use ($run) {
                    $q->whereNull('profit_sharing_effective_until')->orWhereDate('profit_sharing_effective_until', '>=', $run->period_end);
                })
                ->orderBy('last_name')->orderBy('first_name')->get();

            $totalPct = round((float) $partners->sum('profit_sharing_percentage'), 4);
            if ($totalPct > 100.0001) throw new DomainException('Total active partner profit sharing percentage exceeds 100%.');

            $run->lines()->delete();
            $totalShare = 0.0;
            foreach ($partners as $partner) {
                $pct = round((float) $partner->profit_sharing_percentage, 4);
                $share = round($distributable * ($pct / 100), 2);
                $totalShare += $share;
                $run->lines()->create([
                    'personnel_id' => $partner->id,
                    'personnel_code_snapshot' => $partner->code,
                    'personnel_name_snapshot' => $partner->full_name,
                    'percentage_snapshot' => $pct,
                    'distributable_profit_snapshot' => $distributable,
                    'share_amount' => $share,
                    'status' => 'DRAFT',
                ]);
            }

            $run->update([
                'net_income_snapshot' => $netIncome,
                'distributable_profit' => $distributable,
                'total_percentage' => $totalPct,
                'total_profit_share' => round($totalShare, 2),
            ]);
            return $run->fresh(['branch','lines.personnel','paymentAccount']);
        });
    }

    public function approve(User $actor, ProfitSharingRun $run): ProfitSharingRun
    {
        abort_unless((int) $run->business_id === (int) $actor->business_id, 404);
        return DB::transaction(function () use ($actor, $run) {
            $run = ProfitSharingRun::query()->whereKey($run->id)->with('lines')->lockForUpdate()->firstOrFail();
            if ($run->status !== 'DRAFT') throw new DomainException('Only Draft profit sharing runs can be approved.');
            if ((float) $run->total_profit_share <= 0.004) throw new DomainException('Profit sharing total must be greater than zero before approval.');

            $amount = round((float) $run->total_profit_share, 2);
            $journal = $this->journals->postEntry(
                (int) $run->business_id, $run->branch_id, $run->period_end->toDateString(),
                'PROFIT_SHARING_RUN', (int) $run->id, 'APPROVE', $run->code,
                'Approve profit sharing '.$run->code,
                [
                    ['account_key' => 'PARTNER_PROFIT_DISTRIBUTIONS', 'debit' => $amount, 'credit' => 0, 'memo' => 'Partner profit distribution'],
                    ['account_key' => 'PROFIT_SHARING_PAYABLE', 'debit' => 0, 'credit' => $amount, 'memo' => 'Profit sharing payable'],
                ],
                $actor->id,
            );
            if (! $journal) throw new DomainException('Required profit sharing accounting mappings are incomplete.');

            $run->lines()->update(['status' => 'APPROVED']);
            $run->update(['status' => 'APPROVED', 'approved_by' => $actor->id, 'approved_at' => $this->clock->now()]);
            return $run->fresh(['branch','lines.personnel','paymentAccount']);
        });
    }

    public function markPaid(User $actor, ProfitSharingRun $run, int $paymentAccountId): ProfitSharingRun
    {
        abort_unless((int) $run->business_id === (int) $actor->business_id, 404);
        return DB::transaction(function () use ($actor, $run, $paymentAccountId) {
            $run = ProfitSharingRun::query()->whereKey($run->id)->with('lines')->lockForUpdate()->firstOrFail();
            if ($run->status !== 'APPROVED') throw new DomainException('Only Approved profit sharing runs can be marked Paid.');
            $cash = ChartOfAccount::query()->where('business_id', $run->business_id)->whereKey($paymentAccountId)->where('active', true)->where('is_cash_account', true)->firstOrFail();
            $amount = round((float) $run->total_profit_share, 2);
            $journal = $this->journals->postEntry(
                (int) $run->business_id, $run->branch_id, $this->clock->today(),
                'PROFIT_SHARING_RUN', (int) $run->id, 'PAY', $run->code,
                'Pay profit sharing '.$run->code,
                [
                    ['account_key' => 'PROFIT_SHARING_PAYABLE', 'debit' => $amount, 'credit' => 0, 'memo' => 'Settle profit sharing payable'],
                    ['account_id' => $cash->id, 'debit' => 0, 'credit' => $amount, 'memo' => 'Profit sharing payment'],
                ],
                $actor->id,
            );
            if (! $journal) throw new DomainException('Required profit sharing accounting mappings are incomplete.');
            $run->lines()->update(['status' => 'PAID']);
            $run->update(['status' => 'PAID', 'payment_account_id' => $cash->id, 'paid_by' => $actor->id, 'paid_at' => $this->clock->now()]);
            return $run->fresh(['branch','lines.personnel','paymentAccount']);
        });
    }

    private function netIncome(int $businessId, string $from, string $to, ?int $branchId, string $basis, string $costBasis): float
    {
        $accounts = ChartOfAccount::query()->where('business_id', $businessId)
            ->whereIn('account_type', ['INCOME','COST_OF_SALES','OPERATING_EXPENSE'])
            ->where('posting_type', 'POSTING')->get();
        $income = $cost = $opex = 0.0;
        foreach ($accounts as $account) {
            if (strtoupper($costBasis) === 'SYSTEM' && in_array($account->system_key, ['INVENTORY_COUNT_VARIANCE','INVENTORY_COUNT_GAIN'], true)) continue;
            $q = AccountingJournalLine::query()
                ->join('accounting_journal_entries as e', 'e.id', '=', 'accounting_journal_lines.journal_entry_id')
                ->where('e.business_id', $businessId)->where('e.status', 'POSTED')
                ->whereBetween('e.posting_date', [$from, $to])
                ->where('accounting_journal_lines.account_id', $account->id);
            if ($branchId) $q->where('e.branch_id', $branchId);
            if (strtoupper($basis) === 'CASH') {
                $q->whereExists(function ($sub) {
                    $sub->selectRaw('1')->from('accounting_journal_lines as cl')
                        ->join('chart_of_accounts as ca', 'ca.id', '=', 'cl.account_id')
                        ->whereColumn('cl.journal_entry_id', 'e.id')->where('ca.is_cash_account', true);
                });
            }
            $debit = (float) (clone $q)->sum('accounting_journal_lines.debit');
            $credit = (float) (clone $q)->sum('accounting_journal_lines.credit');
            $amount = $account->normal_balance === 'CREDIT' ? $credit - $debit : $debit - $credit;
            if ($account->account_type === 'INCOME') $income += $amount;
            elseif ($account->account_type === 'COST_OF_SALES') $cost += $amount;
            else $opex += $amount;
        }
        return round($income - $cost - $opex, 2);
    }
}
