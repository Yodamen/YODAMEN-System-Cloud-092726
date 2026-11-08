<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Models\AccountingJournalEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DisbursementRegisterController extends Controller
{
    /** Source-linked posted journal entries are the immutable disbursement keys. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable','date'],
            'to' => ['nullable','date'],
            'branch_id' => ['nullable','integer'],
            'source' => ['nullable','string','max:60'],
        ]);
        $allowed = [
            'EXPENSE' => ['PAYMENT', 'POST'],
            'INVENTORY_RECEIPT' => ['PAYMENT', 'POST'],
            'PAYROLL_RUN' => ['PAY'],
            'STAFF_TIP' => ['PAYOUT'],
            'EMPLOYEE_CASH_ADVANCE_LEDGER' => ['ISSUE'],
        ];
        $source = strtoupper((string)($data['source'] ?? 'ALL'));
        $specificSources = [
            'MANUAL_ENTRY' => ['EXPENSE', 'POST'],
            'MANUAL_AP' => ['EXPENSE', 'PAYMENT'],
            'STOCK_RECEIVED_AP' => ['INVENTORY_RECEIPT', 'PAYMENT'],
        ];
        if ($source !== 'ALL' && ! isset($allowed[$source]) && ! isset($specificSources[$source])) {
            return response()->json(['message' => 'Invalid disbursement source.'], 422);
        }
        $query = AccountingJournalEntry::query()
            ->where('business_id', (int)$request->user()->business_id)
            ->where('status', 'POSTED')
            ->where(function ($q) use ($allowed) {
                foreach ($allowed as $type => $events) {
                    $q->orWhere(function ($qq) use ($type, $events) {
                        $qq->where('source_type', $type)->whereIn('source_event', $events);
                    });
                }
            })
            ->when(isset($specificSources[$source]), fn ($q) => $q
                ->where('source_type', $specificSources[$source][0])
                ->where('source_event', $specificSources[$source][1]))
            ->when($source !== 'ALL' && isset($allowed[$source]), fn ($q) => $q->where('source_type', $source))
            ->when(isset($data['from']), fn ($q) => $q->whereDate('posting_date', '>=', $data['from']))
            ->when(isset($data['to']), fn ($q) => $q->whereDate('posting_date', '<=', $data['to']))
            ->when(isset($data['branch_id']), fn ($q) => $q->where('branch_id', $data['branch_id']))
            ->with(['lines.account', 'branch:id,name'])
            ->orderByDesc('posting_date')->orderByDesc('id');

        $rows = $query->limit(1000)->get()->flatMap(function ($journal) {
            // Only credit lines going out of eligible cash/bank/petty accounts
            // count as disbursements. This also excludes unpaid accrued bills
            // and inventory received on credit, without duplicating journals.
            $cashLines = $journal->lines->filter(function ($line) {
                $account = $line->account;
                return $account && (bool)$account->is_cash_account && (float)$line->credit > 0.004
                    && ((bool)$account->is_funding_source || $account->system_key === 'PETTY_CASH');
            });
            if ($cashLines->isEmpty()) return [];
            $amount = round((float)$cashLines->sum(fn ($line) => (float)$line->credit), 2);
            return [[
                'journal_id' => $journal->id,
                'journal_code' => $journal->code,
                'date' => $journal->posting_date?->format('Y-m-d'),
                'source_type' => $journal->source_type,
                'source_id' => $journal->source_id,
                'source_event' => $journal->source_event,
                'source_label' => match (true) {
                    $journal->source_type === 'EXPENSE' && $journal->source_event === 'PAYMENT' => 'Manual Entry (AP Expense)',
                    $journal->source_type === 'EXPENSE' => 'Manual Entry',
                    $journal->source_type === 'INVENTORY_RECEIPT' => 'Stock Received (AP Expense)',
                    $journal->source_type === 'PAYROLL_RUN' => 'Payroll',
                    $journal->source_type === 'STAFF_TIP' => 'Tips Release',
                    $journal->source_type === 'EMPLOYEE_CASH_ADVANCE_LEDGER' => 'Cash Advance',
                    default => $journal->source_type,
                },
                'source_reference' => $journal->reference,
                'description' => $journal->description,
                'branch' => $journal->branch?->name,
                'amount' => $amount,
                'funding_sources' => $cashLines->map(fn ($line) => [
                    'account_id' => $line->account_id,
                    'account' => $line->account_name,
                    'amount' => (float)$line->credit,
                ])->values(),
                'coa_lines' => $journal->lines->map(fn ($line) => [
                    'account_code' => $line->account_code,
                    'account' => $line->account_name,
                    'debit' => (float)$line->debit,
                    'credit' => (float)$line->credit,
                ])->values(),
            ]];
        })->values();
        return response()->json([
            'rows' => $rows,
            'summary' => ['total_disbursed' => round((float)$rows->sum('amount'), 2), 'count' => $rows->count()],
            'scope' => 'POSTED_ACCOUNTING_JOURNALS',
        ]);
    }
}
