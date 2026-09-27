<?php

namespace App\Services\Accounting;

use App\Models\AccountingDailyClosing;
use App\Models\AccountingJournalEntry;
use App\Models\ChartOfAccount;
use App\Models\EmployeeCashAdvance;
use App\Models\User;
use App\Services\Foundation\BusinessClock;
use App\Services\Foundation\CodeSequenceService;
use DomainException;

class AccountingJournalService
{
    public function __construct(
        private readonly CodeSequenceService $codes,
        private readonly BusinessClock $clock,
    ) {
    }


    /**
     * Post one balanced, source-linked journal exactly once.
     *
     * Each line may provide either account_id or account_key plus debit/credit.
     * Missing mappings cause a safe no-op so operational workflows are never
     * blocked before Accounting Mapping has been configured.
     */
    public function postEntry(
        int $businessId,
        ?int $branchId,
        string $postingDate,
        string $sourceType,
        int $sourceId,
        string $sourceEvent,
        ?string $reference,
        string $description,
        array $lines,
        ?int $createdBy = null,
    ): ?AccountingJournalEntry {
        $existing = AccountingJournalEntry::query()
            ->where('business_id', $businessId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('source_event', $sourceEvent)
            ->with('lines')
            ->first();
        if ($existing) return $existing;

        $this->assertPostingDateOpen($businessId, $branchId, $postingDate);

        $resolved = [];
        $debit = 0.0;
        $credit = 0.0;
        foreach ($lines as $line) {
            $d = round(max(0, (float) ($line['debit'] ?? 0)), 2);
            $c = round(max(0, (float) ($line['credit'] ?? 0)), 2);
            if ($d <= 0.004 && $c <= 0.004) continue;

            $account = null;
            if (! empty($line['account_id'])) {
                $account = ChartOfAccount::query()->where('business_id', $businessId)->whereKey((int) $line['account_id'])->first();
            } elseif (! empty($line['account_key'])) {
                $account = ChartOfAccount::query()->where('business_id', $businessId)->where('system_key', (string) $line['account_key'])->first();
            }
            if (! $account || ! $account->active || strtoupper((string) $account->posting_type) !== 'POSTING') return null;

            $debit += $d;
            $credit += $c;
            $resolved[] = [
                'account' => $account,
                'debit' => $d,
                'credit' => $c,
                'memo' => $line['memo'] ?? null,
            ];
        }

        $debit = round($debit, 2);
        $credit = round($credit, 2);
        if (! $resolved || abs($debit - $credit) > 0.009) {
            if (! $resolved) return null;
            throw new DomainException('Automatic accounting journal is not balanced for '.$sourceType.' '.$sourceEvent.'.');
        }

        $now = $this->clock->now();
        $entry = AccountingJournalEntry::query()->create([
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'code' => $this->codes->next('ACCOUNTING_JOURNAL:'.$now->format('my'), 'JRN-'.$now->format('my').'-', 6, $businessId),
            'posting_date' => $postingDate,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_event' => $sourceEvent,
            'reference' => $reference,
            'description' => $description,
            'status' => 'POSTED',
            'posted_at' => $now,
            'created_by' => $createdBy,
        ]);

        foreach ($resolved as $index => $line) {
            /** @var ChartOfAccount $account */
            $account = $line['account'];
            $entry->lines()->create([
                'line_no' => $index + 1,
                'account_id' => $account->id,
                'account_key' => $account->system_key ?: 'ACCOUNT:'.$account->code,
                'account_code' => $account->code,
                'account_name' => $account->name,
                'debit' => $line['debit'],
                'credit' => $line['credit'],
                'memo' => $line['memo'],
            ]);
        }

        return $entry->fresh('lines');
    }

    public function assertPostingDateOpen(int $businessId, ?int $branchId, string $postingDate): void
    {
        if (! $branchId) return;

        if (AccountingDailyClosing::query()
            ->where('business_id', $businessId)
            ->where('branch_id', $branchId)
            ->whereDate('closing_date', $postingDate)
            ->where('status', 'CLOSED')
            ->exists()) {
            throw new DomainException('Accounting is closed for '.$postingDate.'. Reopen the accounting day before posting new transactions to this date.');
        }
    }

    public function accountByKey(int $businessId, string $systemKey): ?ChartOfAccount
    {
        $systemKey = strtoupper(trim($systemKey));
        if ($systemKey === '') return null;

        return ChartOfAccount::query()
            ->where('business_id', $businessId)
            ->where('system_key', $systemKey)
            ->where('active', true)
            ->where('posting_type', 'POSTING')
            ->first();
    }

    public function postCashAdvanceWriteOff(
        User $actor,
        EmployeeCashAdvance $advance,
        float $amount,
        string $postingDate,
        string $reason,
    ): AccountingJournalEntry {
        $existing = AccountingJournalEntry::query()
            ->where('business_id', $advance->business_id)
            ->where('source_type', 'EMPLOYEE_CASH_ADVANCE')
            ->where('source_id', $advance->id)
            ->where('source_event', 'WRITE_OFF')
            ->with('lines')
            ->first();

        if ($existing) return $existing;
        if ($amount <= 0) throw new DomainException('Cash advance write-off journal amount must be greater than zero.');

        $now = $this->clock->now();
        $entry = AccountingJournalEntry::query()->create([
            'business_id' => $advance->business_id,
            'code' => $this->codes->next('ACCOUNTING_JOURNAL:'.$now->format('my'), 'JRN-'.$now->format('my').'-', 6, $advance->business_id),
            'posting_date' => $postingDate,
            'source_type' => 'EMPLOYEE_CASH_ADVANCE',
            'source_id' => $advance->id,
            'source_event' => 'WRITE_OFF',
            'reference' => $advance->code,
            'description' => 'Cash advance write-off - '.$advance->code.'. '.$reason,
            'status' => 'POSTED',
            'posted_at' => $now,
            'created_by' => $actor->id,
        ]);

        $writeOffAccount = ChartOfAccount::query()->where('business_id', $advance->business_id)->where('system_key', 'CASH_ADVANCE_WRITE_OFF_EXPENSE')->first();
        $staffAdvanceAccount = ChartOfAccount::query()->where('business_id', $advance->business_id)->where('system_key', 'STAFF_CASH_ADVANCES')->first();

        $entry->lines()->createMany([
            [
                'line_no' => 1,
                'account_id' => $writeOffAccount?->id,
                'account_key' => 'CASH_ADVANCE_WRITE_OFF_EXPENSE',
                'account_code' => $writeOffAccount?->code,
                'account_name' => $writeOffAccount?->name ?? 'Cash Advance Write-Off Expense',
                'debit' => round($amount, 2),
                'credit' => 0,
                'memo' => 'Approved forgiveness of employee cash advance receivable.',
            ],
            [
                'line_no' => 2,
                'account_id' => $staffAdvanceAccount?->id,
                'account_key' => 'STAFF_CASH_ADVANCES',
                'account_code' => $staffAdvanceAccount?->code ?? '1102',
                'account_name' => $staffAdvanceAccount?->name ?? 'Staff Cash Advances',
                'debit' => 0,
                'credit' => round($amount, 2),
                'memo' => 'Remove written-off employee receivable.',
            ],
        ]);

        return $entry->fresh('lines');
    }
}
