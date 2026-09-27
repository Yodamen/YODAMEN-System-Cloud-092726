<?php

namespace App\Http\Controllers\Api\V1\Expense;

use App\Http\Controllers\Controller;
use App\Models\AccountingJournalEntry;
use App\Models\AccountingJournalLine;
use App\Models\ChartOfAccount;
use App\Models\PettyCashLiquidation;
use App\Models\PettyCashTransaction;
use App\Models\ReversalApprovalRequest;
use App\Services\Accounting\AccountingJournalService;
use App\Services\Accounting\ReversalApprovalService;
use App\Services\Foundation\BusinessClock;
use App\Services\Foundation\CodeSequenceService;
use App\Services\Foundation\SupportingDocumentService;
use App\Services\MasterData\WorkflowReasonCatalog;
use App\Services\MasterData\WorkflowReasonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use DomainException;

class PettyCashController extends Controller
{
    public function __construct(private readonly CodeSequenceService $codes, private readonly BusinessClock $clock, private readonly AccountingJournalService $journals, private readonly ReversalApprovalService $reversalApprovals, private readonly SupportingDocumentService $documents) {}

    public function index(Request $request): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;
        $petty = $this->pettyCashAccount($businessId);
        $branchId = $request->filled('branch_id') ? $request->integer('branch_id') : null;
        $from = $request->query('from');
        $to = $request->query('to');

        $lines = AccountingJournalLine::query()
            ->select('accounting_journal_lines.*')
            ->join('accounting_journal_entries as e', 'e.id', '=', 'accounting_journal_lines.journal_entry_id')
            ->where('accounting_journal_lines.account_id', $petty->id)
            ->where('e.business_id', $businessId)
            ->where('e.status', 'POSTED')
            ->when($branchId, fn ($q) => $q->where('e.branch_id', $branchId))
            ->when($to, fn ($q) => $q->whereDate('e.posting_date', '<=', $to))
            ->with(['journalEntry.branch', 'journalEntry.lines.account'])
            ->orderBy('e.posting_date')
            ->orderBy('e.id')
            ->orderBy('accounting_journal_lines.line_no')
            ->get();

        $manualIds = $lines
            ->filter(fn (AccountingJournalLine $line) => strtoupper((string) $line->journalEntry?->source_type) === 'PETTY_CASH')
            ->map(fn (AccountingJournalLine $line) => (int) $line->journalEntry->source_id)
            ->filter()
            ->unique()
            ->values();
        $manual = PettyCashTransaction::query()
            ->where('business_id', $businessId)
            ->whereIn('id', $manualIds)
            ->get()
            ->keyBy('id');
        $pendingReversals = ReversalApprovalRequest::query()
            ->where('business_id', $businessId)
            ->where('source_type', 'PETTY_CASH')
            ->whereIn('source_id', $manualIds)
            ->where('status', 'PENDING')
            ->with(['requester','reviewer'])
            ->get()
            ->keyBy('source_id');

        $running = 0.0;
        $ledgerRows = collect();
        foreach ($lines as $line) {
            $debit = round((float) $line->debit, 2);
            $credit = round((float) $line->credit, 2);
            $running = round($running + $debit - $credit, 2);
            $entry = $line->journalEntry;
            if (! $entry) continue;
            if ($from && $entry->posting_date?->toDateString() < $from) continue;
            $ledgerRows->push($this->presentLedgerLine($line, $running, $manual, $pendingReversals));
        }

        $latestLiquidations = PettyCashLiquidation::query()
            ->where('business_id', $businessId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->with(['branch', 'creator'])
            ->orderByDesc('liquidation_date')
            ->orderByDesc('id')
            ->get()
            ->groupBy('branch_id')
            ->map(fn (Collection $rows) => $rows->first());

        $unliquidatedOutflow = $lines->sum(function (AccountingJournalLine $line) use ($latestLiquidations) {
            $entry = $line->journalEntry;
            if (! $entry) return 0;
            $last = $latestLiquidations->get($entry->branch_id);
            $through = (int) ($last?->through_journal_entry_id ?? 0);
            return $entry->id > $through ? (float) $line->credit : 0;
        });

        $periodRows = $ledgerRows;
        $reversedSourceKeys = $lines->filter(function (AccountingJournalLine $line) {
            $event = strtoupper((string) $line->journalEntry?->source_event);
            return in_array($event, ['REVERSE','REVERSAL'], true);
        })->map(fn (AccountingJournalLine $line) => strtoupper((string) $line->journalEntry->source_type).':'.(int) $line->journalEntry->source_id)->unique()->values();
        $activePeriodRows = $periodRows->filter(function (array $row) use ($reversedSourceKeys) {
            $event = strtoupper((string) ($row['source_event'] ?? ''));
            if (in_array($event, ['REVERSE','REVERSAL'], true)) return false;
            return ! $reversedSourceKeys->contains(strtoupper((string) $row['source_type']).':'.(int) $row['source_id']);
        });
        $reversalRows = $periodRows->filter(fn (array $row) => in_array(strtoupper((string) ($row['source_event'] ?? '')), ['REVERSE','REVERSAL'], true));
        $liquidations = PettyCashLiquidation::query()
            ->where('business_id', $businessId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($from, fn ($q) => $q->whereDate('liquidation_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('liquidation_date', '<=', $to))
            ->with(['branch', 'creator'])
            ->orderByDesc('liquidation_date')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json([
            'rows' => $periodRows->sortByDesc(fn (array $row) => sprintf('%s-%012d-%06d', $row['transaction_date'], $row['journal_entry_id'], $row['line_no']))->values(),
            'summary' => [
                'balance' => round($running, 2),
                'replenished' => round($activePeriodRows->where('source_type', 'PETTY_CASH')->where('source_event', 'REPLENISHMENT')->sum('in'), 2),
                'disbursed' => round($activePeriodRows->sum('out'), 2),
                'reversed' => round($reversalRows->sum(fn (array $row) => max((float) $row['in'], (float) $row['out'])), 2),
                'unliquidated_outflow' => round($unliquidatedOutflow, 2),
            ],
            'liquidations' => $liquidations->map(fn (PettyCashLiquidation $row) => $this->presentLiquidation($row))->values(),
            'options' => [
                'branches' => DB::table('branches')->where('business_id', $businessId)->where('status', 'ACTIVE')->orderBy('name')->get(['id', 'name']),
                'funding_sources' => ChartOfAccount::query()
                    ->where('business_id', $businessId)
                    ->where('active', true)
                    ->where('posting_type', 'POSTING')
                    ->where('id', '!=', $petty->id)
                    ->where('is_funding_source', true)
                    ->orderBy('code')
                    ->get(['id', 'code', 'name', 'account_type']),
            ],
        ]);
    }

    /**
     * Manual Petty Cash creation is intentionally limited to replenishment.
     * Expense/payroll/AP/stock cash-outs must originate from their operational module.
     */
    public function store(Request $request): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;
        $request->merge([
            'funding_account_id' => $request->input('funding_account_id', $request->input('counterpart_account_id')),
        ]);
        $data = $request->validate([
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('business_id', $businessId)],
            'transaction_date' => ['required', 'date'],
            'transaction_type' => ['nullable', Rule::in(['REPLENISHMENT'])],
            'direction' => ['nullable', Rule::in(['IN'])],
            'funding_account_id' => ['required', 'integer', Rule::exists('chart_of_accounts', 'id')->where('business_id', $businessId)],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reference' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'attachment_tokens' => ['sometimes','array','max:10'],
            'attachment_tokens.*' => ['required','uuid','distinct'],
        ]);

        $petty = $this->pettyCashAccount($businessId);
        $funding = ChartOfAccount::query()
            ->whereKey($data['funding_account_id'])
            ->where('business_id', $businessId)
            ->where('active', true)
            ->where('posting_type', 'POSTING')
            ->where('id', '!=', $petty->id)
            ->where('is_funding_source', true)
            ->firstOrFail();

        $this->journals->assertPostingDateOpen($businessId, (int) $data['branch_id'], $data['transaction_date']);

        $row = DB::transaction(function () use ($request, $data, $businessId, $petty, $funding) {
            $now = $this->clock->now();
            $amount = round((float) $data['amount'], 2);
            $tx = PettyCashTransaction::query()->create([
                'business_id' => $businessId,
                'branch_id' => $data['branch_id'],
                'code' => $this->codes->next('PETTY_CASH:'.$now->format('my'), 'PC-'.$now->format('my').'-', 6, $businessId),
                'transaction_date' => $data['transaction_date'],
                'transaction_type' => 'REPLENISHMENT',
                'direction' => 'IN',
                'counterpart_account_id' => $funding->id,
                'amount' => $amount,
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'] ?? null,
                'status' => 'POSTED',
                'created_by' => $request->user()->id,
            ]);
            $journal = AccountingJournalEntry::query()->create([
                'business_id' => $businessId,
                'branch_id' => $data['branch_id'],
                'code' => $this->codes->next('ACCOUNTING_JOURNAL:'.$now->format('my'), 'JRN-'.$now->format('my').'-', 6, $businessId),
                'posting_date' => $data['transaction_date'],
                'source_type' => 'PETTY_CASH',
                'source_id' => $tx->id,
                'source_event' => 'REPLENISHMENT',
                'reference' => $tx->code,
                'description' => trim(($data['description'] ?? '') ?: 'Petty cash replenishment '.$tx->code),
                'status' => 'POSTED',
                'posted_at' => $now,
                'created_by' => $request->user()->id,
            ]);
            $journal->lines()->createMany([
                [
                    'line_no' => 1,
                    'account_id' => $petty->id,
                    'account_key' => $petty->system_key ?: 'COA_'.$petty->code,
                    'account_code' => $petty->code,
                    'account_name' => $petty->name,
                    'debit' => $amount,
                    'credit' => 0,
                    'memo' => 'Petty cash replenishment',
                ],
                [
                    'line_no' => 2,
                    'account_id' => $funding->id,
                    'account_key' => $funding->system_key ?: 'COA_'.$funding->code,
                    'account_code' => $funding->code,
                    'account_name' => $funding->name,
                    'debit' => 0,
                    'credit' => $amount,
                    'memo' => 'Fund petty cash from '.$funding->name,
                ],
            ]);
            $tx->update(['journal_entry_id' => $journal->id]);
            $this->documents->claimTokens($request->user(), $data['attachment_tokens'] ?? [], 'petty-cash', 'PETTY_CASH', (int) $tx->id, 'PETTY_CASH_REPLENISHMENT_SUPPORT');
            return $tx->fresh(['branch', 'counterpartAccount', 'creator']);
        });

        return response()->json(['transaction' => $this->presentManualTransaction($row)], 201);
    }

    public function liquidationPreview(Request $request): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;
        $data = $request->validate([
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('business_id', $businessId)],
            'liquidation_date' => ['required', 'date'],
        ]);

        return response()->json(['preview' => $this->liquidationSnapshot($businessId, (int) $data['branch_id'], $data['liquidation_date'])]);
    }

    public function liquidate(Request $request): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;
        $data = $request->validate([
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('business_id', $businessId)],
            'liquidation_date' => ['required', 'date'],
            'actual_cash_count' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $snapshot = $this->liquidationSnapshot($businessId, (int) $data['branch_id'], $data['liquidation_date']);
        if (! $snapshot['through_journal_entry_id']) {
            return response()->json(['message' => 'There are no posted Petty Cash transactions to liquidate for this branch.'], 422);
        }
        if ($snapshot['transaction_count'] <= 0) {
            return response()->json(['message' => 'There are no new Petty Cash transactions since the last liquidation.'], 422);
        }

        $actual = round((float) $data['actual_cash_count'], 2);
        $variance = round($actual - (float) $snapshot['book_balance'], 2);
        if (abs($variance) > 0.009 && trim((string) ($data['notes'] ?? '')) === '') {
            return response()->json(['message' => 'Notes are required when the actual cash count does not match the Petty Cash book balance.'], 422);
        }
        $status = abs($variance) <= 0.009 ? 'BALANCED' : ($variance < 0 ? 'SHORT' : 'OVER');
        $now = $this->clock->now();

        $row = PettyCashLiquidation::query()->create([
            'business_id' => $businessId,
            'branch_id' => $data['branch_id'],
            'code' => $this->codes->next('PETTY_CASH_LIQUIDATION:'.$now->format('my'), 'PCL-'.$now->format('my').'-', 6, $businessId),
            'liquidation_date' => $data['liquidation_date'],
            'period_from' => $snapshot['period_from'],
            'period_to' => $data['liquidation_date'],
            'through_journal_entry_id' => $snapshot['through_journal_entry_id'],
            'book_balance' => $snapshot['book_balance'],
            'actual_cash_count' => $actual,
            'variance_amount' => $variance,
            'covered_outflow' => $snapshot['covered_outflow'],
            'reconciliation_status' => $status,
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => $status === 'BALANCED'
                ? 'Petty Cash liquidation recorded and reconciled.'
                : 'Petty Cash liquidation recorded with a variance. The variance does not alter accounting until separately reviewed and adjusted.',
            'liquidation' => $this->presentLiquidation($row->fresh(['branch', 'creator'])),
        ], 201);
    }

    public function reverse(Request $request, PettyCashTransaction $pettyCashTransaction, WorkflowReasonService $reasons): JsonResponse
    {
        abort_unless((int) $pettyCashTransaction->business_id === (int) $request->user()->business_id, 404);
        $data = $request->validate(['reason_reference_id' => ['required','integer'], 'reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $reasonReference = $reasons->resolve($request->user(), (int) $data['reason_reference_id'], WorkflowReasonCatalog::PETTY_CASH_REVERSAL);
        if ($pettyCashTransaction->status !== 'POSTED') return response()->json(['message' => 'Only posted Petty Cash replenishments can be submitted for reversal.'], 422);
        try {
            $approval = $this->reversalApprovals->request($request->user(), 'petty-cash', 'PETTY_CASH', (int) $pettyCashTransaction->id, $pettyCashTransaction->code, $data['reason'], (int) $reasonReference->id);
            return response()->json(['message' => 'Petty Cash reversal submitted for approval.', 'reversal_request' => ReversalApprovalService::serialize($approval)], 201);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function approveReversal(Request $request, ReversalApprovalRequest $reversalApprovalRequest): JsonResponse
    {
        $data = $request->validate(['notes' => ['nullable','string','max:1000']]);
        try {
            $approved = $this->reversalApprovals->approve($request->user(), $reversalApprovalRequest, 'PETTY_CASH', function (ReversalApprovalRequest $approval) use ($request) {
                $transaction = PettyCashTransaction::query()->where('business_id',$request->user()->business_id)->findOrFail($approval->source_id);
                return $this->applyReversal($request->user(), $transaction, $approval->reason);
            }, $data['notes'] ?? null);
            return response()->json(['message'=>'Petty Cash reversal approved and posted.','reversal_request'=>ReversalApprovalService::serialize($approved['request']),'transaction'=>$this->presentManualTransaction($approved['result'])]);
        } catch (DomainException $e) {
            return response()->json(['message'=>$e->getMessage()],422);
        }
    }

    public function rejectReversal(Request $request, ReversalApprovalRequest $reversalApprovalRequest, WorkflowReasonService $reasons): JsonResponse
    {
        $data = $request->validate(['review_reason_reference_id' => ['required','integer'], 'notes' => ['required','string','min:3','max:1000']]);
        $reviewReason = $reasons->resolve($request->user(), (int) $data['review_reason_reference_id'], WorkflowReasonCatalog::APPROVAL_REJECTION);
        try {
            $row = $this->reversalApprovals->reject($request->user(), $reversalApprovalRequest, 'PETTY_CASH', $data['notes'], (int) $reviewReason->id);
            return response()->json(['message'=>'Petty Cash reversal request rejected.','reversal_request'=>ReversalApprovalService::serialize($row)]);
        } catch (DomainException $e) {
            return response()->json(['message'=>$e->getMessage()],422);
        }
    }

    private function applyReversal(\App\Models\User $actor, PettyCashTransaction $pettyCashTransaction, string $reason): PettyCashTransaction
    {
        if ($pettyCashTransaction->status !== 'POSTED') throw new DomainException('This Petty Cash transaction is no longer available for reversal.');
        return DB::transaction(function () use ($actor, $pettyCashTransaction, $reason) {
            $locked = PettyCashTransaction::query()->whereKey($pettyCashTransaction->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'POSTED') throw new DomainException('This Petty Cash transaction is no longer available for reversal.');
            $now = $this->clock->now();
            $source = $locked->journalEntry()->with('lines')->firstOrFail();
            $this->journals->assertPostingDateOpen((int) $source->business_id, $source->branch_id ? (int) $source->branch_id : null, $now->toDateString());
            $reversal = AccountingJournalEntry::query()->create([
                'business_id'=>$source->business_id,'branch_id'=>$source->branch_id,
                'code'=>$this->codes->next('ACCOUNTING_JOURNAL:'.$now->format('my'),'JRN-'.$now->format('my').'-',6,$source->business_id),
                'posting_date'=>$now->toDateString(),'source_type'=>'PETTY_CASH','source_id'=>$locked->id,'source_event'=>'REVERSAL',
                'reference'=>$locked->code,'description'=>'Reversal of '.$locked->code.': '.$reason,'status'=>'POSTED','posted_at'=>$now,'created_by'=>$actor->id,
            ]);
            foreach ($source->lines as $line) {
                $reversal->lines()->create([
                    'line_no'=>$line->line_no,'account_id'=>$line->account_id,'account_key'=>$line->account_key,'account_code'=>$line->account_code,
                    'account_name'=>$line->account_name,'debit'=>$line->credit,'credit'=>$line->debit,'memo'=>'Reversal: '.$reason,
                ]);
            }
            $locked->update(['status'=>'REVERSED','reversed_at'=>$now,'reversed_by'=>$actor->id,'reversal_reason'=>$reason]);
            return $locked->fresh(['branch','counterpartAccount','creator']);
        });
    }

    private function liquidationSnapshot(int $businessId, int $branchId, string $liquidationDate): array
    {
        $petty = $this->pettyCashAccount($businessId);
        $last = PettyCashLiquidation::query()
            ->where('business_id', $businessId)
            ->where('branch_id', $branchId)
            ->orderByDesc('through_journal_entry_id')
            ->orderByDesc('id')
            ->first();
        $lastThrough = (int) ($last?->through_journal_entry_id ?? 0);

        $lines = AccountingJournalLine::query()
            ->select('accounting_journal_lines.*', 'e.posting_date as journal_posting_date')
            ->join('accounting_journal_entries as e', 'e.id', '=', 'accounting_journal_lines.journal_entry_id')
            ->where('accounting_journal_lines.account_id', $petty->id)
            ->where('e.business_id', $businessId)
            ->where('e.branch_id', $branchId)
            ->where('e.status', 'POSTED')
            ->whereDate('e.posting_date', '<=', $liquidationDate)
            ->orderBy('e.posting_date')
            ->orderBy('e.id')
            ->get();

        $bookBalance = round($lines->sum(fn ($line) => (float) $line->debit - (float) $line->credit), 2);
        $uncovered = $lines->filter(fn ($line) => (int) $line->journal_entry_id > $lastThrough);
        $through = (int) ($lines->max('journal_entry_id') ?? 0);
        $periodFrom = $uncovered->first()?->journal_posting_date;

        return [
            'book_balance' => $bookBalance,
            'covered_outflow' => round($uncovered->sum('credit'), 2),
            'covered_inflow' => round($uncovered->sum('debit'), 2),
            'transaction_count' => $uncovered->count(),
            'period_from' => $periodFrom ? substr((string) $periodFrom, 0, 10) : null,
            'period_to' => $liquidationDate,
            'through_journal_entry_id' => $through ?: null,
            'last_liquidation' => $last ? $this->presentLiquidation($last->loadMissing(['branch', 'creator'])) : null,
        ];
    }

    private function presentLedgerLine(AccountingJournalLine $line, float $balanceAfter, Collection $manual, Collection $pendingReversals): array
    {
        $entry = $line->journalEntry;
        $debit = round((float) $line->debit, 2);
        $credit = round((float) $line->credit, 2);
        $counterparts = $entry->lines
            ->where('account_id', '!=', $line->account_id)
            ->map(fn (AccountingJournalLine $other) => [
                'id' => $other->account_id,
                'code' => $other->account_code,
                'name' => $other->account_name,
                'debit' => (float) $other->debit,
                'credit' => (float) $other->credit,
            ])
            ->values();
        $manualTx = strtoupper((string) $entry->source_type) === 'PETTY_CASH'
            ? $manual->get((int) $entry->source_id)
            : null;

        return [
            'id' => 'J'.$entry->id.'-L'.$line->id,
            'journal_entry_id' => (int) $entry->id,
            'line_no' => (int) $line->line_no,
            'transaction_date' => $entry->posting_date?->toDateString(),
            'journal_code' => $entry->code,
            'reference' => $entry->reference ?: $entry->code,
            'source_type' => strtoupper((string) $entry->source_type),
            'source_id' => (int) $entry->source_id,
            'source_event' => strtoupper((string) $entry->source_event),
            'source_label' => $this->sourceLabel((string) $entry->source_type, (string) $entry->source_event),
            'description' => $entry->description ?: $line->memo,
            'memo' => $line->memo,
            'direction' => $debit >= $credit ? 'IN' : 'OUT',
            'amount' => max($debit, $credit),
            'in' => $debit,
            'out' => $credit,
            'balance_after' => round($balanceAfter, 2),
            'branch' => $entry->branch?->only(['id', 'name']),
            'counterpart_accounts' => $counterparts,
            'reversible_transaction_id' => $manualTx && $manualTx->status === 'POSTED' ? (int) $manualTx->id : null,
            'manual_transaction_status' => $manualTx?->status,
            'pending_reversal' => ReversalApprovalService::serialize($manualTx ? $pendingReversals->get((int) $manualTx->id) : null),
        ];
    }

    private function sourceLabel(string $sourceType, string $sourceEvent): string
    {
        $type = strtoupper($sourceType);
        $event = strtoupper($sourceEvent);
        return match ($type) {
            'PETTY_CASH' => $event === 'REPLENISHMENT' ? 'Replenishment' : ($event === 'REVERSAL' ? 'Replenishment Reversal' : str($event)->replace('_', ' ')->title()->toString()),
            'EXPENSE' => 'Expense',
            'PAYROLL_RUN' => 'Payroll',
            'INVENTORY_RECEIPT' => 'Stock Received / Supplier',
            'PROFIT_SHARING' => 'Profit Sharing',
            'EMPLOYEE_CASH_ADVANCE', 'EMPLOYEE_CASH_ADVANCE_LEDGER' => 'Cash Advance',
            'TRANSACTION_REFUND' => 'Customer Refund',
            default => str($type)->replace('_', ' ')->title()->toString(),
        };
    }

    private function presentLiquidation(PettyCashLiquidation $row): array
    {
        return [
            'id' => $row->id,
            'code' => $row->code,
            'liquidation_date' => $row->liquidation_date?->toDateString(),
            'period_from' => $row->period_from?->toDateString(),
            'period_to' => $row->period_to?->toDateString(),
            'book_balance' => (float) $row->book_balance,
            'actual_cash_count' => (float) $row->actual_cash_count,
            'variance_amount' => (float) $row->variance_amount,
            'covered_outflow' => (float) $row->covered_outflow,
            'reconciliation_status' => $row->reconciliation_status,
            'notes' => $row->notes,
            'branch' => $row->branch?->only(['id', 'name']),
            'created_by' => $row->creator?->display_name,
        ];
    }

    private function presentManualTransaction(PettyCashTransaction $row): array
    {
        return [
            'id' => $row->id,
            'code' => $row->code,
            'transaction_date' => $row->transaction_date?->toDateString(),
            'transaction_type' => $row->transaction_type,
            'direction' => $row->direction,
            'amount' => (float) $row->amount,
            'reference' => $row->reference,
            'description' => $row->description,
            'status' => $row->status,
            'branch' => $row->branch?->only(['id', 'name']),
            'counterpart_account' => $row->counterpartAccount?->only(['id', 'code', 'name']),
            'created_by' => $row->creator?->display_name,
            'reversal_reason' => $row->reversal_reason,
        ];
    }

    private function pettyCashAccount(int $businessId): ChartOfAccount
    {
        return ChartOfAccount::query()
            ->where('business_id', $businessId)
            ->where('system_key', 'PETTY_CASH')
            ->where('active', true)
            ->where('posting_type', 'POSTING')
            ->firstOrFail();
    }
}
