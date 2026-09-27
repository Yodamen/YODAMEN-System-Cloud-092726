<?php

namespace App\Services\Accounting;

use App\Models\AccountingJournalLine;
use App\Models\BankReconciliation;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Services\Foundation\BusinessClock;
use App\Services\Foundation\CodeSequenceService;
use DomainException;
use Illuminate\Support\Facades\DB;

class BankReconciliationService
{
    public function __construct(
        private readonly AccountingJournalService $journals,
        private readonly CodeSequenceService $codes,
        private readonly BusinessClock $clock,
    ) {}

    public function saveDraft(User $actor, array $data, ?BankReconciliation $record = null): BankReconciliation
    {
        return DB::transaction(function () use ($actor, $data, $record) {
            if ($record) {
                abort_unless((int) $record->business_id === (int) $actor->business_id, 404);
                if ($record->status !== 'DRAFT') throw new DomainException('Only Draft bank reconciliations can be edited.');
            }

            $account = ChartOfAccount::query()
                ->where('business_id', $actor->business_id)
                ->whereKey((int) $data['bank_account_id'])
                ->where('active', true)
                ->where('is_cash_account', true)
                ->firstOrFail();

            $book = $this->bookBalance((int) $actor->business_id, $account->id, $data['statement_date'], $data['branch_id'] ?? null);
            $bankAdjustments = collect($data['items'] ?? [])->where('effect_on', 'BANK')->sum('amount');
            $bookAdjustments = collect($data['items'] ?? [])->where('effect_on', 'BOOK')->sum('amount');
            $adjustedBank = round((float) $data['statement_ending_balance'] + (float) $bankAdjustments, 2);
            $adjustedBook = round($book + (float) $bookAdjustments, 2);
            $difference = round($adjustedBank - $adjustedBook, 2);

            $row = $record ?: new BankReconciliation([
                'business_id' => $actor->business_id,
                'code' => $this->codes->next('BANK_RECON:'.$this->clock->now()->format('my'), 'BRC-'.$this->clock->now()->format('my').'-', 5, $actor->business_id),
                'created_by' => $actor->id,
            ]);
            $row->fill([
                'branch_id' => $data['branch_id'] ?? null,
                'bank_account_id' => $account->id,
                'statement_date' => $data['statement_date'],
                'statement_ending_balance' => round((float) $data['statement_ending_balance'], 2),
                'book_balance_snapshot' => $book,
                'adjusted_bank_balance' => $adjustedBank,
                'adjusted_book_balance' => $adjustedBook,
                'difference' => $difference,
                'notes' => $data['notes'] ?? null,
                'status' => 'DRAFT',
            ]);
            $row->save();

            $row->items()->delete();
            foreach (array_values($data['items'] ?? []) as $index => $item) {
                $row->items()->create([
                    'line_no' => $index + 1,
                    'item_type' => strtoupper($item['item_type']),
                    'effect_on' => strtoupper($item['effect_on']),
                    'description' => $item['description'],
                    'reference' => $item['reference'] ?? null,
                    'amount' => round((float) $item['amount'], 2),
                    'adjustment_account_id' => $item['adjustment_account_id'] ?? null,
                ]);
            }

            return $row->fresh(['branch','bankAccount','items.adjustmentAccount']);
        });
    }

    public function reconcile(User $actor, BankReconciliation $record): BankReconciliation
    {
        abort_unless((int) $record->business_id === (int) $actor->business_id, 404);
        return DB::transaction(function () use ($actor, $record) {
            $record = BankReconciliation::query()->whereKey($record->id)->with(['items.adjustmentAccount','bankAccount'])->lockForUpdate()->firstOrFail();
            if ($record->status !== 'DRAFT') throw new DomainException('Only Draft bank reconciliations can be finalized.');

            $book = $this->bookBalance((int) $record->business_id, (int) $record->bank_account_id, $record->statement_date->toDateString(), $record->branch_id);
            $bankAdjustments = (float) $record->items->where('effect_on', 'BANK')->sum('amount');
            $bookAdjustments = (float) $record->items->where('effect_on', 'BOOK')->sum('amount');
            $adjustedBank = round((float) $record->statement_ending_balance + $bankAdjustments, 2);
            $adjustedBook = round($book + $bookAdjustments, 2);
            $difference = round($adjustedBank - $adjustedBook, 2);
            $record->update(['book_balance_snapshot' => $book, 'adjusted_bank_balance' => $adjustedBank, 'adjusted_book_balance' => $adjustedBook, 'difference' => $difference]);
            if (abs($difference) > 0.009) throw new DomainException('Bank reconciliation difference must be zero before finalizing. Refresh the draft because the book balance may have changed.');

            foreach ($record->items as $item) {
                if ($item->effect_on !== 'BOOK' || abs((float) $item->amount) <= 0.004) continue;
                if (! $item->adjustment_account_id) throw new DomainException('Book-side reconciliation adjustments require an adjustment account.');
                $amount = round(abs((float) $item->amount), 2);
                $lines = (float) $item->amount > 0
                    ? [
                        ['account_id' => $record->bank_account_id, 'debit' => $amount, 'credit' => 0, 'memo' => $item->description],
                        ['account_id' => $item->adjustment_account_id, 'debit' => 0, 'credit' => $amount, 'memo' => $item->description],
                    ]
                    : [
                        ['account_id' => $item->adjustment_account_id, 'debit' => $amount, 'credit' => 0, 'memo' => $item->description],
                        ['account_id' => $record->bank_account_id, 'debit' => 0, 'credit' => $amount, 'memo' => $item->description],
                    ];
                $journal = $this->journals->postEntry(
                    (int) $record->business_id, $record->branch_id, $record->statement_date->toDateString(),
                    'BANK_RECON_ITEM', (int) $item->id, 'ADJUST', $item->reference ?: $record->code,
                    'Bank reconciliation adjustment '.$record->code.' - '.$item->description,
                    $lines, $actor->id,
                );
                if ($journal) $item->update(['journal_entry_id' => $journal->id]);
            }

            $record->update(['status' => 'RECONCILED', 'reconciled_by' => $actor->id, 'reconciled_at' => $this->clock->now()]);
            return $record->fresh(['branch','bankAccount','items.adjustmentAccount','items.journalEntry','reconciler']);
        });
    }

    public function bookBalance(int $businessId, int $accountId, string $date, ?int $branchId = null): float
    {
        $q = AccountingJournalLine::query()
            ->join('accounting_journal_entries as e', 'e.id', '=', 'accounting_journal_lines.journal_entry_id')
            ->where('e.business_id', $businessId)
            ->where('e.status', 'POSTED')
            ->whereDate('e.posting_date', '<=', $date)
            ->where('accounting_journal_lines.account_id', $accountId);
        if ($branchId) $q->where('e.branch_id', $branchId);
        return round((float) (clone $q)->sum('accounting_journal_lines.debit') - (float) (clone $q)->sum('accounting_journal_lines.credit'), 2);
    }
}
