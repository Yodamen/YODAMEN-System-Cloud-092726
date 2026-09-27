<?php

namespace App\Services\Accounting;

use App\Models\AccountingJournalEntry;
use App\Models\AccountingPaymentMethodMapping;
use App\Models\ReferenceValue;
use App\Models\ChartOfAccount;
use App\Models\Expense;
use App\Models\ExpenseReversalRequest;
use App\Models\User;
use App\Services\Foundation\BusinessClock;
use App\Services\Foundation\CodeSequenceService;
use App\Services\Foundation\SupportingDocumentService;
use App\Services\Security\AuditService;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ExpenseService
{
    public function __construct(
        private readonly CodeSequenceService $codes,
        private readonly BusinessClock $clock,
        private readonly AccountingJournalService $journals,
        private readonly AuditService $audit,
        private readonly SupportingDocumentService $documents,
    ) {
    }

    public function create(User $actor, array $data): Expense
    {
        return DB::transaction(function () use ($actor, $data) {
            $now = $this->clock->now();
            $total = $this->lineTotal($data['lines']);
            $resolvedSplits = $data['payment_status'] === 'PAID'
                ? $this->resolvePaymentSplits($actor->business_id, $this->paymentSplitsFromData($data, $total))
                : [];
            $firstSplit = $resolvedSplits[0] ?? null;
            $expense = Expense::query()->create([
                'business_id' => $actor->business_id,
                'branch_id' => $data['branch_id'],
                'code' => $this->codes->nextAvailable(
                    'EXPENSE:'.$now->format('my'),
                    'EXP-'.$now->format('my').'-',
                    fn (string $candidate) => Expense::query()->where('code', $candidate)->exists(),
                    6,
                    $actor->business_id,
                ),
                'expense_date' => $data['expense_date'],
                'vendor_payee' => $data['vendor_payee'],
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'] ?? null,
                'total_amount' => $total,
                'status' => 'DRAFT',
                'payment_status' => $data['payment_status'],
                'payment_splits' => $data['payment_status'] === 'PAID' ? $resolvedSplits : null,
                'payment_account_id' => $firstSplit['funding_account_id'] ?? $firstSplit['cash_account_id'] ?? null,
                'payment_method' => $firstSplit['payment_method'] ?? null,
                'payment_reference' => $firstSplit['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->replaceLines($expense, $data['lines']);
            return $this->fresh($expense);
        });
    }

    public function update(User $actor, Expense $expense, array $data): Expense
    {
        if ($expense->status !== 'DRAFT') {
            throw new DomainException('Only Draft expenses can be edited. Posted financial history must be reversed instead.');
        }

        return DB::transaction(function () use ($actor, $expense, $data) {
            $total = $this->lineTotal($data['lines']);
            $resolvedSplits = $data['payment_status'] === 'PAID'
                ? $this->resolvePaymentSplits($actor->business_id, $this->paymentSplitsFromData($data, $total))
                : [];
            $firstSplit = $resolvedSplits[0] ?? null;
            $expense->update([
                'branch_id' => $data['branch_id'],
                'expense_date' => $data['expense_date'],
                'vendor_payee' => $data['vendor_payee'],
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'] ?? null,
                'total_amount' => $total,
                'payment_status' => $data['payment_status'],
                'payment_splits' => $data['payment_status'] === 'PAID' ? $resolvedSplits : null,
                'payment_account_id' => $firstSplit['funding_account_id'] ?? $firstSplit['cash_account_id'] ?? null,
                'payment_method' => $firstSplit['payment_method'] ?? null,
                'payment_reference' => $firstSplit['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'updated_by' => $actor->id,
            ]);

            $this->replaceLines($expense, $data['lines']);
            return $this->fresh($expense);
        });
    }

    public function post(User $actor, Expense $expense): Expense
    {
        if ($expense->status !== 'DRAFT') {
            throw new DomainException('Only Draft expenses can be posted.');
        }
        if (! ($expense->attachment_disk && $expense->attachment_path)
            && ! $this->documents->hasAttached((int) $expense->business_id, 'EXPENSE', (int) $expense->id)) {
            throw new DomainException('A supporting document is required before this expense can be posted.');
        }

        return DB::transaction(function () use ($actor, $expense) {
            $expense->loadMissing(['lines.account', 'paymentAccount']);
            if ($expense->lines->isEmpty() || (float) $expense->total_amount <= 0) {
                throw new DomainException('Expense must contain at least one valid line before posting.');
            }

            $journalLines = [];
            $lineNo = 1;
            foreach ($expense->lines as $line) {
                $journalLines[] = $this->journalLine($lineNo++, $line->account, (float) $line->amount, 0, $line->description ?: $expense->description);
            }
            if ($expense->payment_status === 'PAID') {
                $splits = $this->storedPaymentSplits($expense, true);
                foreach ($splits as $split) {
                    $account = ChartOfAccount::query()->where('business_id',$expense->business_id)->findOrFail((int) ($split['funding_account_id'] ?? $split['cash_account_id']));
                    $journalLines[] = $this->journalLine($lineNo++, $account, 0, (float) $split['amount'], 'Expense funded via '.($split['funding_source'] ?? $split['payment_method'] ?? $account->name).'.');
                }
            } else {
                $journalLines[] = $this->journalLine($lineNo++, $this->requireSystemAccount($expense->business_id, 'ACCOUNTS_PAYABLE'), 0, (float) $expense->total_amount, 'Expense accrued to accounts payable.');
            }

            $this->createJournal($actor, $expense, 'POST', $journalLines, 'Expense '.$expense->code.' posted.');

            $now = $this->clock->now();
            $expense->update([
                'status' => 'POSTED',
                'posted_at' => $now,
                'posted_by' => $actor->id,
                'paid_at' => $expense->payment_status === 'PAID' ? $now : null,
                'paid_by' => $expense->payment_status === 'PAID' ? $actor->id : null,
                'updated_by' => $actor->id,
            ]);

            return $this->fresh($expense);
        });
    }

    public function markPaid(User $actor, Expense $expense, array $paymentSplits): Expense
    {
        if ($expense->status !== 'POSTED') throw new DomainException('Only Posted expenses can be marked paid.');
        if ($expense->payment_status === 'PAID') throw new DomainException('Expense is already paid.');
        if (ExpenseReversalRequest::query()->where('expense_id',$expense->id)->where('status','PENDING')->exists()) throw new DomainException('Resolve the pending expense reversal request before marking this expense paid.');
        $splits = $this->resolvePaymentSplits($expense->business_id, $paymentSplits);
        $total = round((float) collect($splits)->sum('amount'), 2);
        if (abs($total - (float) $expense->total_amount) > 0.01) throw new DomainException('Split payment total must equal the expense total.');

        return DB::transaction(function () use ($actor, $expense, $splits) {
            $ap = $this->requireSystemAccount($expense->business_id, 'ACCOUNTS_PAYABLE');
            $lines = [$this->journalLine(1, $ap, (float) $expense->total_amount, 0, 'Settle expense payable.')];
            $lineNo = 2;
            foreach ($splits as $split) {
                $account = ChartOfAccount::query()->where('business_id',$expense->business_id)->findOrFail((int) ($split['funding_account_id'] ?? $split['cash_account_id']));
                $lines[] = $this->journalLine($lineNo++, $account, 0, (float) $split['amount'], 'Expense payable funded via '.($split['funding_source'] ?? $split['payment_method'] ?? $account->name).'.');
            }
            $this->createJournal($actor, $expense, 'PAYMENT', $lines, 'Expense '.$expense->code.' paid.', $this->clock->today());
            $first = $splits[0] ?? null;
            $expense->update([
                'payment_status' => 'PAID',
                'payment_splits' => $splits,
                'payment_account_id' => $first['funding_account_id'] ?? $first['cash_account_id'] ?? null,
                'payment_method' => $first['payment_method'] ?? null,
                'payment_reference' => $first['reference'] ?? null,
                'paid_at' => $this->clock->now(), 'paid_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
            return $this->fresh($expense);
        });
    }

    public function requestReversal(User $actor, Expense $expense, string $reason, ?int $reasonReferenceId = null): ExpenseReversalRequest
    {
        if ((int) $expense->business_id !== (int) $actor->business_id) abort(404);
        $reason = trim($reason);
        if ($reason === '') throw new DomainException('Reversal reason is required.');

        return DB::transaction(function () use ($actor, $expense, $reason, $reasonReferenceId) {
            $lockedExpense = Expense::query()
                ->where('business_id', $actor->business_id)
                ->whereKey($expense->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedExpense->status !== 'POSTED') throw new DomainException('Only Posted expenses can be submitted for reversal.');
            if (ExpenseReversalRequest::query()->where('expense_id',$lockedExpense->id)->where('status','PENDING')->exists()) {
                throw new DomainException('This expense already has a pending reversal request. Approve or reject it before creating another request.');
            }

            $now = $this->clock->now();
            $request = ExpenseReversalRequest::query()->create([
                'business_id'=>$actor->business_id,
                'code'=>$this->codes->next('EXPENSE_REVERSAL_REQUEST:'.$actor->business_id.':'.$now->format('my'),'EXR-'.$now->format('my').'-',5,$actor->business_id),
                'expense_id'=>$lockedExpense->id,'status'=>'PENDING','reason'=>$reason,'reason_reference_id'=>$reasonReferenceId,'requested_by'=>$actor->id,
            ]);
            $this->audit->record(action:'REQUEST_EXPENSE_REVERSAL', module:'expenses', entityType:ExpenseReversalRequest::class, entityId:$request->id, after:$request->toArray(), reason:$reason, actor:$actor);
            return $request->fresh(['expense','requester','reviewer','reasonReference','reviewReasonReference']);
        });
    }

    public function approveReversal(User $actor, ExpenseReversalRequest $request, ?string $notes = null): Expense
    {
        if ((int) $request->business_id !== (int) $actor->business_id) abort(404);
        return DB::transaction(function () use ($actor, $request, $notes) {
            $locked = ExpenseReversalRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'PENDING') throw new DomainException('Only Pending expense reversal requests can be approved.');
            $expense = Expense::query()->whereKey($locked->expense_id)->lockForUpdate()->firstOrFail();
            if ($expense->status !== 'POSTED') throw new DomainException('The expense is no longer Posted and cannot be reversed.');
            $this->applyReversal($actor, $expense, $locked->reason);
            $locked->update(['status'=>'APPROVED','reviewed_by'=>$actor->id,'reviewed_at'=>$this->clock->now(),'review_notes'=>$notes]);
            $this->audit->record(action:'APPROVE_EXPENSE_REVERSAL', module:'expenses', entityType:ExpenseReversalRequest::class, entityId:$locked->id, before:['status'=>'PENDING'], after:['status'=>'APPROVED'], reason:$notes, actor:$actor);
            return $this->fresh($expense);
        });
    }

    public function rejectReversal(User $actor, ExpenseReversalRequest $request, string $notes, ?int $reviewReasonReferenceId = null): ExpenseReversalRequest
    {
        if ((int) $request->business_id !== (int) $actor->business_id) abort(404);
        $notes = trim($notes);
        if ($notes === '') throw new DomainException('Rejection reason is required.');
        if ($request->status !== 'PENDING') throw new DomainException('Only Pending expense reversal requests can be rejected.');
        $request->update(['status'=>'REJECTED','reviewed_by'=>$actor->id,'reviewed_at'=>$this->clock->now(),'review_reason_reference_id'=>$reviewReasonReferenceId,'review_notes'=>$notes]);
        $this->audit->record(action:'REJECT_EXPENSE_REVERSAL', module:'expenses', entityType:ExpenseReversalRequest::class, entityId:$request->id, before:['status'=>'PENDING'], after:['status'=>'REJECTED'], reason:$notes, actor:$actor);
        return $request->fresh(['expense','requester','reviewer','reasonReference','reviewReasonReference']);
    }

    private function applyReversal(User $actor, Expense $expense, string $reason): void
    {
        $expense->loadMissing(['lines.account', 'paymentAccount']);
        $journalLines = [];
        $lineNo = 1;
        if ($expense->payment_status === 'PAID') {
            foreach ($this->storedPaymentSplits($expense, false) as $split) {
                $account = ChartOfAccount::query()->where('business_id',$expense->business_id)->findOrFail((int) ($split['funding_account_id'] ?? $split['cash_account_id']));
                $journalLines[] = $this->journalLine($lineNo++, $account, (float) $split['amount'], 0, 'Reverse expense funding via '.($split['funding_source'] ?? $split['payment_method'] ?? $account->name).'.');
            }
        } else {
            $journalLines[] = $this->journalLine($lineNo++, $this->requireSystemAccount($expense->business_id, 'ACCOUNTS_PAYABLE'), (float) $expense->total_amount, 0, 'Reverse expense accrual.');
        }
        foreach ($expense->lines as $line) {
            $journalLines[] = $this->journalLine($lineNo++, $line->account, 0, (float) $line->amount, $line->description ?: 'Reverse expense line.');
        }
        $this->createJournal($actor, $expense, 'REVERSE', $journalLines, 'Expense '.$expense->code.' reversed. '.$reason, $this->clock->today());
        $expense->update(['status'=>'REVERSED','reversed_at'=>$this->clock->now(),'reversed_by'=>$actor->id,'reversal_reason'=>$reason,'updated_by'=>$actor->id]);
    }

    public function storeAttachment(User $actor, Expense $expense, UploadedFile $file): Expense
    {
        if ($expense->status === 'REVERSED') {
            throw new DomainException('Supporting documents cannot be changed after an expense is reversed.');
        }
        if (ExpenseReversalRequest::query()->where('expense_id',$expense->id)->where('status','PENDING')->exists()) {
            throw new DomainException('Supporting documents cannot be changed while an expense reversal request is pending.');
        }

        if ($expense->attachment_disk && $expense->attachment_path) {
            Storage::disk($expense->attachment_disk)->delete($expense->attachment_path);
        }

        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $file->getClientOriginalName()) ?: 'document';
        $path = $file->storeAs('expenses/'.$expense->business_id.'/'.$expense->code, now()->format('YmdHis').'-'.$safeName, 'local');

        $expense->update([
            'attachment_disk' => 'local',
            'attachment_path' => $path,
            'attachment_name' => $file->getClientOriginalName(),
            'attachment_mime' => $file->getMimeType(),
            'updated_by' => $actor->id,
        ]);

        return $this->fresh($expense);
    }

    private function replaceLines(Expense $expense, array $lines): void
    {
        $expense->lines()->delete();
        foreach (array_values($lines) as $index => $line) {
            $expense->lines()->create([
                'line_no' => $index + 1,
                'account_id' => $line['account_id'],
                'description' => $line['description'] ?? null,
                'amount' => round((float) $line['amount'], 2),
            ]);
        }
    }

    private function paymentSplitsFromData(array $data, float $total): array
    {
        $splits = $data['payment_splits'] ?? [];
        if (! $splits && ! empty($data['payment_account_id'])) {
            $splits = [[
                'cash_account_id' => (int) $data['payment_account_id'],
                'payment_method' => $data['payment_method'] ?? null,
                'amount' => $total,
                'reference' => $data['payment_reference'] ?? null,
            ]];
        }
        return $splits;
    }

    private function resolvePaymentSplits(int $businessId, array $splits): array
    {
        $resolved = [];
        foreach ($splits as $split) {
            $amount = round((float) ($split['amount'] ?? 0), 2);
            if ($amount <= 0) throw new DomainException('Funding split amount must be greater than zero.');

            $fundingAccountId = (int) ($split['funding_account_id'] ?? $split['cash_account_id'] ?? 0);
            if ($fundingAccountId > 0) {
                $account = ChartOfAccount::query()
                    ->where('business_id', $businessId)
                    ->whereKey($fundingAccountId)
                    ->where('active', true)
                    ->where('posting_type', 'POSTING')
                    ->where('is_funding_source', true)
                    ->first();
                if (! $account) throw new DomainException('Select a valid active Expense / Supplier Funding Source.');
                $resolved[] = [
                    'funding_account_id' => (int) $account->id,
                    'funding_source' => $account->name,
                    'payment_method_reference_id' => null,
                    'payment_method' => trim((string) ($split['payment_method'] ?? '')) ?: $account->name,
                    'cash_account_id' => $account->is_cash_account ? (int) $account->id : null,
                    'cash_account' => $account->is_cash_account ? $account->name : null,
                    'amount' => $amount,
                    'reference' => $split['reference'] ?? null,
                ];
                continue;
            }

            // Legacy clients may still send a mapped customer payment method. Keep it supported
            // without exposing those methods in the current expense UI.
            $methodId = (int) ($split['payment_method_reference_id'] ?? 0);
            $mapping = AccountingPaymentMethodMapping::query()
                ->where('business_id', $businessId)
                ->where('payment_method_reference_id', $methodId)
                ->with(['cashAccount','paymentMethod'])
                ->first();
            if (
                ! $mapping
                || ! $mapping->cashAccount
                || ! $mapping->cashAccount->active
                || strtoupper((string) $mapping->cashAccount->posting_type) !== 'POSTING'
                || ! $mapping->cashAccount->is_cash_account
                || ! $mapping->cashAccount->is_funding_source
            ) {
                throw new DomainException('Every legacy payment method must be mapped to an active Expense / Supplier Funding Source.');
            }
            $resolved[] = [
                'funding_account_id' => (int) $mapping->cash_account_id,
                'funding_source' => $mapping->cashAccount->name,
                'payment_method_reference_id' => $methodId,
                'payment_method' => $mapping->paymentMethod?->display_value ?: 'Payment',
                'cash_account_id' => (int) $mapping->cash_account_id,
                'cash_account' => $mapping->cashAccount->name,
                'amount' => $amount,
                'reference' => $split['reference'] ?? null,
            ];
        }
        if (! $resolved) throw new DomainException('Add at least one Funding Source.');
        return $resolved;
    }

    private function storedPaymentSplits(Expense $expense, bool $requireActive): array
    {
        $splits = $expense->payment_splits ?: [];
        if (! $splits && $expense->payment_account_id) {
            $account = ChartOfAccount::query()->where('business_id', $expense->business_id)->find((int) $expense->payment_account_id);
            $splits = [[
                'funding_account_id' => (int) $expense->payment_account_id,
                'funding_source' => $account?->name ?: ($expense->payment_method ?: 'Funding Source'),
                'cash_account_id' => $account?->is_cash_account ? (int) $account->id : null,
                'payment_method' => $expense->payment_method ?: ($account?->name ?: 'Funding Source'),
                'amount' => (float) $expense->total_amount,
                'reference' => $expense->payment_reference,
            ]];
        }
        if (! $splits) throw new DomainException('The historical funding details for this expense are unavailable.');
        if ($requireActive) {
            foreach ($splits as $split) {
                $accountId = (int) ($split['funding_account_id'] ?? $split['cash_account_id'] ?? 0);
                $valid = ChartOfAccount::query()
                    ->where('business_id', $expense->business_id)
                    ->whereKey($accountId)
                    ->where('active', true)
                    ->where('posting_type', 'POSTING')
                    ->where('is_funding_source', true)
                    ->exists();
                if (! $valid) throw new DomainException('A Funding Source used by this expense is inactive or unavailable.');
            }
        }
        return $splits;
    }

    private function lineTotal(array $lines): float
    {
        return round(array_sum(array_map(fn ($line) => (float) ($line['amount'] ?? 0), $lines)), 2);
    }

    private function requirePaymentAccount(Expense $expense, bool $requireActive = true): ChartOfAccount
    {
        $account = $expense->paymentAccount;
        if (! $account || ! $account->is_cash_account || ($requireActive && ! $account->active)) {
            throw new DomainException($requireActive
                ? 'A valid active payment account is required for a paid expense.'
                : 'The historical payment account for this expense is unavailable.');
        }
        return $account;
    }

    private function requireSystemAccount(int $businessId, string $systemKey): ChartOfAccount
    {
        return ChartOfAccount::query()
            ->where('business_id', $businessId)
            ->where('system_key', $systemKey)
            ->where('active', true)
            ->firstOrFail();
    }

    private function journalLine(int $lineNo, ChartOfAccount $account, float $debit, float $credit, ?string $memo): array
    {
        return [
            'line_no' => $lineNo,
            'account_id' => $account->id,
            'account_key' => $account->system_key ?: 'COA:'.$account->code,
            'account_code' => $account->code,
            'account_name' => $account->name,
            'debit' => round($debit, 2),
            'credit' => round($credit, 2),
            'memo' => $memo,
        ];
    }

    private function createJournal(User $actor, Expense $expense, string $event, array $lines, string $description, ?string $postingDate = null): AccountingJournalEntry
    {
        $existing = AccountingJournalEntry::query()
            ->where('business_id', $expense->business_id)
            ->where('source_type', 'EXPENSE')
            ->where('source_id', $expense->id)
            ->where('source_event', $event)
            ->first();
        if ($existing) {
            throw new DomainException('This expense accounting event has already been posted.');
        }

        $debit = round(array_sum(array_column($lines, 'debit')), 2);
        $credit = round(array_sum(array_column($lines, 'credit')), 2);
        if ($debit <= 0 || abs($debit - $credit) > 0.001) {
            throw new DomainException('Accounting journal is not balanced.');
        }

        $effectivePostingDate = $postingDate ?: $expense->expense_date->toDateString();
        $this->journals->assertPostingDateOpen((int) $expense->business_id, (int) $expense->branch_id, $effectivePostingDate);

        $now = $this->clock->now();
        $entry = AccountingJournalEntry::query()->create([
            'business_id' => $expense->business_id,
            'branch_id' => $expense->branch_id,
            'code' => $this->codes->nextAvailable(
                'ACCOUNTING_JOURNAL:'.$now->format('my'),
                'JRN-'.$now->format('my').'-',
                fn (string $candidate) => AccountingJournalEntry::query()->where('code', $candidate)->exists(),
                6,
                $expense->business_id,
            ),
            'posting_date' => $effectivePostingDate,
            'source_type' => 'EXPENSE',
            'source_id' => $expense->id,
            'source_event' => $event,
            'reference' => $expense->reference ?: $expense->code,
            'description' => $description,
            'status' => 'POSTED',
            'posted_at' => $now,
            'created_by' => $actor->id,
        ]);
        $entry->lines()->createMany($lines);
        return $entry->fresh('lines');
    }

    private function fresh(Expense $expense): Expense
    {
        return $expense->fresh([
            'branch:id,name,short_code',
            'lines.account:id,code,name,account_type,account_group',
            'paymentAccount:id,code,name',
            'creator:id,name', 'poster:id,name', 'payer:id,name', 'reverser:id,name',
            'reversalRequests.requester:id,name', 'reversalRequests.reviewer:id,name',
        ]);
    }
}
