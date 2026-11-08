<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Models\AccountingJournalLine;
use App\Models\ChartOfAccount;
use App\Models\Expense;
use App\Models\InventoryReceipt;
use App\Models\InventoryStockReturn;
use App\Services\Accounting\ExpenseService;
use App\Services\Foundation\SupportingDocumentService;
use App\Services\Inventory\InventoryControlService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AccountsPayableController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;
        $asOf = $request->query('as_of', now()->toDateString());
        $branchId = $request->filled('branch_id') ? $request->integer('branch_id') : null;
        $source = strtoupper((string) $request->query('source', 'ALL'));
        $state = strtoupper((string) $request->query('state', 'OPEN'));
        if (! in_array($state, ['OPEN', 'PAID'], true)) $state = 'OPEN';
        $search = trim((string) $request->query('search', ''));
        $dueFrom = $request->filled('due_from') ? $request->query('due_from') : null;
        $dueTo = $request->filled('due_to') ? $request->query('due_to') : null;
        $request->validate([
            'due_from' => ['nullable', 'date'],
            'due_to' => ['nullable', 'date', 'after_or_equal:due_from'],
        ]);

        $rows = collect();

        if (in_array($source, ['ALL', 'EXPENSE'], true)) {
            $expenses = Expense::query()
                ->where('business_id', $businessId)
                ->where('status', 'POSTED')
                ->where('payment_status', $state === 'PAID' ? '=' : '<>', 'PAID')
                ->when($state === 'PAID', fn ($q) => $q->whereIn('id', DB::table('ap_control_numbers')->where('business_id', $businessId)->where('source_type', 'EXPENSE')->select('source_id')))
                ->whereDate('expense_date', '<=', $asOf)
                ->when($dueFrom, fn ($q) => $q->whereDate('due_date', '>=', $dueFrom))
                ->when($dueTo, fn ($q) => $q->whereDate('due_date', '<=', $dueTo))
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->when($search !== '', function ($q) use ($search) {
                    $q->where(function ($w) use ($search) {
                        $w->where('code', 'like', '%'.$search.'%')
                            ->orWhere('vendor_payee', 'like', '%'.$search.'%')
                            ->orWhere('reference', 'like', '%'.$search.'%');
                    });
                })
                ->with('branch:id,name')
                ->get();

            foreach ($expenses as $expense) {
                $rows->push($this->payableRow(
                    'EXPENSE',
                    (int) $expense->id,
                    $expense->code,
                    $expense->expense_date?->toDateString(),
                    $expense->vendor_payee,
                    $expense->branch?->name,
                    (float) $expense->total_amount,
                    $state === 'PAID' ? 0.0 : (float) $expense->total_amount,
                    $expense->reference,
                    $asOf,
                    [],
                    $expense->due_date?->toDateString(),
                ));
            }
        }

        // Stock receipts do not yet carry due dates. Date-filtered views exclude unknown due dates.
        if (! $dueFrom && ! $dueTo && in_array($source, ['ALL', 'INVENTORY_RECEIPT'], true)) {
            $receipts = InventoryReceipt::query()
                ->where('business_id', $businessId)
                ->where('status', 'POSTED')
                ->where('payment_status', $state === 'PAID' ? '=' : '<>', 'PAID')
                ->when($state === 'PAID', fn ($q) => $q->whereIn('id', DB::table('ap_control_numbers')->where('business_id', $businessId)->where('source_type', 'INVENTORY_RECEIPT')->select('source_id')))
                ->whereDate('received_date', '<=', $asOf)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->when($search !== '', function ($q) use ($search) {
                    $q->where(function ($w) use ($search) {
                        $w->where('code', 'like', '%'.$search.'%')
                            ->orWhere('supplier_name', 'like', '%'.$search.'%')
                            ->orWhere('reference_no', 'like', '%'.$search.'%')
                            ->orWhereHas('supplier', fn ($sq) => $sq->where('company_name', 'like', '%'.$search.'%')->orWhere('code_name', 'like', '%'.$search.'%'));
                    });
                })
                ->with(['branch:id,name','supplier:id,company_name,code_name','lines.product'])
                ->get();

            foreach ($receipts as $receipt) {
                $payee = $receipt->supplier?->code_name ?: $receipt->supplier?->company_name ?: $receipt->supplier_name ?: 'Supplier';
                $amount = round((float) $receipt->total_cost + (float) $receipt->delivery_fee, 2);
                $paidAtReceipt = round((float) collect($receipt->payment_splits ?: [])->sum(fn ($split) => (float) ($split['amount'] ?? 0)), 2);
                $returnCredits = round((float) InventoryStockReturn::query()
                    ->where('inventory_receipt_id', $receipt->id)
                    ->where('status', 'APPROVED')
                    ->sum('ap_credit_amount'), 2);
                $outstanding = round(max(0, $amount - $paidAtReceipt - $returnCredits), 2);
                if ($state === 'OPEN' && $outstanding <= 0.009) continue;

                $rows->push($this->payableRow(
                    'INVENTORY_RECEIPT',
                    (int) $receipt->id,
                    $receipt->code,
                    $receipt->received_date?->toDateString(),
                    $payee,
                    $receipt->branch?->name,
                    $amount,
                    $outstanding,
                    $receipt->reference_no,
                    $asOf,
                    $receipt->lines->map(fn ($line) => [
                        'name' => $line->product?->name ?: 'Unnamed item',
                        'sku' => $line->product?->sku,
                        'purchase_quantity' => (float) ($line->purchase_quantity ?? $line->quantity_received),
                        'purchase_uom' => $line->purchase_uom ?: $line->product?->unit,
                    ])->values()->all(),
                ));
            }
        }

        // AP references remain stable after settlement and independently identify the payable.
        $numberMap = DB::table('ap_control_numbers')->where('business_id', $businessId)
            ->get(['source_type', 'source_id', 'code'])
            ->keyBy(fn ($number) => $number->source_type.':'.$number->source_id);
        $rows = $rows->map(function (array $row) use ($numberMap) {
            $row['ap_control_no'] = $numberMap->get($row['source_type'].':'.$row['source_id'])?->code;
            return $row;
        });
        $rows = $rows->sortBy([['document_date', 'desc'], ['source_id', 'desc'], ['code', 'desc']])->values();
        $operationalTotal = round((float) $rows->sum('outstanding_amount'), 2);
        $ledgerBalance = $this->ledgerBalance($businessId, $asOf, $branchId);
        $canReconcile = $state === 'OPEN' && $source === 'ALL' && $search === '' && ! $dueFrom && ! $dueTo;
        $variance = $canReconcile ? round($ledgerBalance - $operationalTotal, 2) : null;

        $summary = [
            'total' => $operationalTotal,
            'ledger_balance' => $ledgerBalance,
            'variance' => $variance,
            'reconciliation_status' => ! $canReconcile ? 'FILTERED_VIEW' : (abs((float) $variance) <= 0.01 ? 'BALANCED' : 'NEEDS_REVIEW'),
            'current' => round((float) $rows->where('aging_bucket', 'CURRENT')->sum('outstanding_amount'), 2),
            '1_30' => round((float) $rows->where('aging_bucket', '1-30')->sum('outstanding_amount'), 2),
            '31_60' => round((float) $rows->where('aging_bucket', '31-60')->sum('outstanding_amount'), 2),
            '61_90' => round((float) $rows->where('aging_bucket', '61-90')->sum('outstanding_amount'), 2),
            'over_90' => round((float) $rows->where('aging_bucket', 'OVER_90')->sum('outstanding_amount'), 2),
        ];

        return response()->json([
            'rows' => $rows,
            'summary' => $summary,
            'options' => $this->options($businessId),
            'as_of' => $asOf,
        ]);
    }

    /**
     * Create a manual unpaid AP bill through the existing Expense posting waterfall.
     * No second journal is created here. Recurring bills may use the same payee
     * provided each period has its own bill date / invoice reference.
     */
    public function storeManual(
        Request $request,
        ExpenseService $expenseService,
        SupportingDocumentService $documents,
    ): JsonResponse {
        $businessId = (int) $request->user()->business_id;
        $data = $request->validate([
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('business_id', $businessId)],
            'expense_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:expense_date'],
            'vendor_payee' => ['required', 'string', 'max:180'],
            'reference' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'attachment_tokens' => ['required', 'array', 'min:1', 'max:10'],
            'attachment_tokens.*' => ['required', 'uuid', 'distinct'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.amount' => ['required', 'numeric', 'gt:0', 'max:999999999999.99'],
        ]);

        $accountIds = collect($data['lines'])->pluck('account_id')->unique()->values();
        $validCount = ChartOfAccount::query()
            ->where('business_id', $businessId)
            ->whereIn('id', $accountIds)
            ->where('active', true)
            ->where('posting_type', 'POSTING')
            ->where(function ($q) {
                $q->whereIn('account_type', ['OPERATING_EXPENSE', 'COST_OF_SALES'])
                    ->orWhere(function ($q) {
                        $q->where('account_type', 'ASSET')
                            ->whereNull('system_key')->where('is_cash_account', false);
                    });
            })->count();
        if ($validCount !== $accountIds->count()) {
            return response()->json(['message' => 'Select valid expense or asset COA accounts.'], 422);
        }

        try {
            $expense = DB::transaction(function () use ($request, $data, $businessId, $expenseService, $documents) {
                // A business-row lock serializes concurrent manual AP submissions.
                DB::table('businesses')->where('id', $businessId)->lockForUpdate()->first();
                $normalizedPayee = mb_strtolower(trim($data['vendor_payee']));
                $normalizedRef = mb_strtolower(trim($data['reference']));
                $duplicate = Expense::query()
                    ->where('business_id', $businessId)
                    ->where('branch_id', $data['branch_id'])
                    ->whereDate('expense_date', $data['expense_date'])
                    ->whereRaw('LOWER(TRIM(vendor_payee)) = ?', [$normalizedPayee])
                    ->whereRaw('LOWER(TRIM(reference)) = ?', [$normalizedRef])
                    ->where('status', '<>', 'REVERSED')
                    ->exists();
                $sameReceipt = InventoryReceipt::query()
                    ->where('business_id', $businessId)
                    ->where('branch_id', $data['branch_id'])
                    ->whereRaw('LOWER(TRIM(reference_no)) = ?', [$normalizedRef])
                    ->where(function ($q) use ($normalizedPayee) {
                        $q->whereRaw('LOWER(TRIM(supplier_name)) = ?', [$normalizedPayee])
                            ->orWhereHas('supplier', fn ($supplier) => $supplier
                                ->whereRaw('LOWER(TRIM(company_name)) = ?', [$normalizedPayee])
                                ->orWhereRaw('LOWER(TRIM(code_name)) = ?', [$normalizedPayee]));
                    })
                    ->where('status', 'POSTED')
                    ->exists();
                if ($duplicate || $sameReceipt) {
                    throw new DomainException('This bill reference is already recorded for the selected branch and payee/date, or matches a posted stock receipt. Open the existing source instead of creating another payable.');
                }
                $expense = $expenseService->create($request->user(), [
                    ...$data,
                    'payment_status' => 'UNPAID',
                    'payment_splits' => [],
                ]);
                $documents->claimTokens($request->user(), $data['attachment_tokens'], 'expenses', 'EXPENSE', (int)$expense->id, 'EXPENSE_SUPPORT');
                return $expenseService->post($request->user(), $expense);
            });
            return response()->json([
                'message' => 'Manual AP bill posted. Pay it once through Accounts Payable.',
                'source_type' => 'EXPENSE',
                'source_id' => $expense->id,
                'source_code' => $expense->code,
            ], 201);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function manualOptions(Request $request): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;
        return response()->json([
            'branches' => DB::table('branches')->where('business_id', $businessId)->where('status', 'ACTIVE')->orderBy('name')->get(['id','name']),
            'accounts' => ChartOfAccount::query()
                ->where('business_id', $businessId)->where('active', true)
                ->where('posting_type', 'POSTING')
                ->where(function ($q) {
                    $q->whereIn('account_type', ['OPERATING_EXPENSE','COST_OF_SALES'])
                        ->orWhere(function ($q) {
                            $q->where('account_type', 'ASSET')->whereNull('system_key')->where('is_cash_account', false);
                        });
                })->orderBy('code')->get(['id','code','name']),
        ]);
    }

    public function pay(
        Request $request,
        string $sourceType,
        int $sourceId,
        ExpenseService $expenseService,
        InventoryControlService $inventoryService,
    ): JsonResponse {
        $businessId = (int) $request->user()->business_id;
        $data = $request->validate([
            'payment_splits' => ['required','array','min:1'],
            'payment_splits.*.funding_account_id' => ['nullable','integer'],
            'payment_splits.*.payment_method_reference_id' => ['nullable','integer'],
            'payment_splits.*.amount' => ['required','numeric','gt:0'],
            'payment_splits.*.reference' => ['nullable','string','max:180'],
        ]);

        $sourceType = strtoupper(str_replace('-', '_', $sourceType));

        try {
            if ($sourceType === 'EXPENSE') {
                $expense = Expense::query()->where('business_id', $businessId)->findOrFail($sourceId);
                $row = $expenseService->markPaid($request->user(), $expense, $data['payment_splits']);
                return response()->json(['message' => 'Accounts payable expense settled.', 'source_type' => 'EXPENSE', 'source' => $row]);
            }

            if ($sourceType === 'INVENTORY_RECEIPT') {
                $receipt = InventoryReceipt::query()->where('business_id', $businessId)->findOrFail($sourceId);
                $row = $inventoryService->markReceiptPaid($request->user(), $receipt, $data['payment_splits']);
                return response()->json(['message' => 'Supplier payable settled.', 'source_type' => 'INVENTORY_RECEIPT', 'source' => $inventoryService->serializeReceipt($row)]);
            }

            return response()->json(['message' => 'Unsupported accounts payable source.'], 422);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    private function payableRow(
        string $sourceType,
        int $sourceId,
        string $code,
        ?string $date,
        string $payee,
        ?string $branch,
        float $originalAmount,
        float $outstandingAmount,
        ?string $reference,
        string $asOf,
        array $items = [],
        ?string $dueDate = null,
    ): array {
        $documentDate = $date ?: $asOf;
        $age = max(0, CarbonImmutable::parse($documentDate)->startOfDay()->diffInDays(CarbonImmutable::parse($asOf)->startOfDay(), false));
        $bucket = $age <= 0 ? 'CURRENT' : ($age <= 30 ? '1-30' : ($age <= 60 ? '31-60' : ($age <= 90 ? '61-90' : 'OVER_90')));

        return [
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'code' => $code,
            'document_date' => $documentDate,
            'due_date' => $dueDate,
            'payee' => $payee,
            'branch' => $branch,
            'reference' => $reference,
            'items' => $items,
            'original_amount' => round($originalAmount, 2),
            'outstanding_amount' => round($outstandingAmount, 2),
            'age_days' => $age,
            'aging_bucket' => $bucket,
            'payment_status' => $outstandingAmount <= 0.009 ? 'PAID' : 'UNPAID',
        ];
    }

    private function ledgerBalance(int $businessId, string $asOf, ?int $branchId): float
    {
        $ap = ChartOfAccount::query()
            ->where('business_id', $businessId)
            ->where('system_key', 'ACCOUNTS_PAYABLE')
            ->where('active', true)
            ->first();
        if (! $ap) return 0.0;

        $query = AccountingJournalLine::query()
            ->join('accounting_journal_entries as e', 'e.id', '=', 'accounting_journal_lines.journal_entry_id')
            ->where('e.business_id', $businessId)
            ->where('e.status', 'POSTED')
            ->whereDate('e.posting_date', '<=', $asOf)
            ->where('accounting_journal_lines.account_id', $ap->id)
            ->when($branchId, fn ($q) => $q->where('e.branch_id', $branchId));

        return round((float) (clone $query)->sum('accounting_journal_lines.credit') - (float) (clone $query)->sum('accounting_journal_lines.debit'), 2);
    }

    private function options(int $businessId): array
    {
        $fundingSources = ChartOfAccount::query()
            ->where('business_id', $businessId)
            ->where('active', true)
            ->where('posting_type', 'POSTING')
            ->where('is_funding_source', true)
            ->orderBy('code')
            ->get(['id','code','name','account_type','is_cash_account'])
            ->map(fn (ChartOfAccount $account) => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'account_type' => $account->account_type,
                'is_cash_account' => (bool) $account->is_cash_account,
            ])->values();

        return [
            'branches' => DB::table('branches')->where('business_id', $businessId)->where('status', 'ACTIVE')->orderBy('name')->get(['id','name']),
            'funding_sources' => $fundingSources,
            'sources' => [
                ['code' => 'ALL', 'name' => 'All Payables'],
                ['code' => 'EXPENSE', 'name' => 'Expenses'],
                ['code' => 'INVENTORY_RECEIPT', 'name' => 'Stock Received'],
            ],
        ];
    }
}
