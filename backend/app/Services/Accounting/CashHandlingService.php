<?php

namespace App\Services\Accounting;

use App\Models\AccountingJournalLine;
use App\Models\CashDeposit;
use App\Models\CashRemittance;
use App\Models\ChartOfAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;

class CashHandlingService
{
    public function cashOnHandAccount(User $actor): ChartOfAccount
    {
        $account = ChartOfAccount::query()
            ->where('business_id', $actor->business_id)
            ->where('active', true)
            ->where('posting_type', 'POSTING')
            ->where('is_cash_account', true)
            ->where(function ($query) {
                $query->where('system_key', 'CASH_ON_HAND')
                    ->orWhereRaw('UPPER(name) LIKE ?', ['%CASH ON HAND%']);
            })
            ->first();

        if (! $account) throw new DomainException('Cash on Hand posting account is not configured.');
        return $account;
    }

    public function custodyAccount(User $actor): ChartOfAccount
    {
        $account = ChartOfAccount::query()
            ->where('business_id', $actor->business_id)
            ->where('active', true)
            ->where('posting_type', 'POSTING')
            ->where('system_key', 'CASH_WITH_MANAGER')
            ->first();

        if (! $account) throw new DomainException('Cash with Manager / Undeposited Cash account is not configured. Run the latest migration or repair the Chart of Accounts.');
        return $account;
    }

    public function expectedCashSales(int $businessId, ?int $branchId, string $businessDate, int $sourceAccountId): float
    {
        return round((float) AccountingJournalLine::query()
            ->join('accounting_journal_entries as aje', 'aje.id', '=', 'accounting_journal_lines.journal_entry_id')
            ->where('aje.business_id', $businessId)
            ->when($branchId, fn ($query) => $query->where('aje.branch_id', $branchId))
            ->whereDate('aje.posting_date', $businessDate)
            ->where('accounting_journal_lines.account_id', $sourceAccountId)
            ->where(function ($query) {
                $query->where(function ($payment) {
                    $payment->where('aje.source_type', 'PAYMENT')->whereIn('aje.source_event', ['RECEIVE', 'REVERSE_RECEIPT']);
                })->orWhere(function ($refund) {
                    $refund->where('aje.source_type', 'TRANSACTION_REFUND')->where('aje.source_event', 'CASH_REFUND');
                });
            })
            ->selectRaw('COALESCE(SUM(accounting_journal_lines.debit - accounting_journal_lines.credit), 0) as net_cash')
            ->value('net_cash'), 2);
    }

    public function dayStatus(User $actor, ?int $branchId, string $businessDate): array
    {
        $cash = $this->cashOnHandAccount($actor);
        $expected = $this->expectedCashSales($actor->business_id, $branchId, $businessDate, $cash->id);

        $remitted = (float) CashRemittance::query()
            ->where('business_id', $actor->business_id)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereDate('business_date', $businessDate)
            ->where('status', 'POSTED')
            ->sum('amount');

        $directDeposited = (float) CashDeposit::query()
            ->where('business_id', $actor->business_id)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereDate('business_date', $businessDate)
            ->where('status', 'POSTED')
            ->where(function ($query) {
                $query->whereNull('cash_remittance_id')->orWhere('deposit_source', 'DIRECT');
            })
            ->sum('amount');

        $remittedDeposited = (float) CashDeposit::query()
            ->where('business_id', $actor->business_id)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereDate('business_date', $businessDate)
            ->where('status', 'POSTED')
            ->whereNotNull('cash_remittance_id')
            ->sum('amount');

        $remittedOpen = max(0, $remitted - $remittedDeposited);
        $deposited = $directDeposited + $remittedDeposited;
        $handled = $deposited + $remittedOpen;
        $cashierPending = max(0, $expected - $directDeposited - $remitted);
        $bankPending = max(0, $expected - $deposited);

        $status = 'NO_CASH';
        if ($expected > 0.009) {
            if ($deposited >= $expected - 0.009) $status = 'DEPOSITED';
            elseif ($handled >= $expected - 0.009 && $remittedOpen > 0.009) $status = 'REMITTED';
            elseif ($handled > 0.009) $status = 'PARTIAL';
            else $status = 'PENDING';
        }

        return [
            'business_date' => $businessDate,
            'expected_cash_sales' => round($expected, 2),
            'remitted_total' => round($remitted, 2),
            'remitted_open' => round($remittedOpen, 2),
            'deposited_direct' => round($directDeposited, 2),
            'deposited_from_remittance' => round($remittedDeposited, 2),
            'deposited_total' => round($deposited, 2),
            'handled_total' => round($handled, 2),
            'remaining_at_cashier' => round($cashierPending, 2),
            'remaining_to_bank' => round($bankPending, 2),
            'status' => $status,
        ];
    }

    public function calendar(User $actor, string $month, ?int $branchId = null): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
        $end = $start->endOfMonth();
        $days = [];
        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            $days[] = $this->dayStatus($actor, $branchId, $date->toDateString());
        }

        return [
            'month' => $start->format('Y-m'),
            'month_label' => $start->format('F Y'),
            'days' => $days,
            'summary' => [
                'cash_sales' => round((float) collect($days)->sum('expected_cash_sales'), 2),
                'remitted_open' => round((float) collect($days)->sum('remitted_open'), 2),
                'deposited' => round((float) collect($days)->sum('deposited_total'), 2),
                'remaining_to_bank' => round((float) collect($days)->sum('remaining_to_bank'), 2),
            ],
        ];
    }
}
