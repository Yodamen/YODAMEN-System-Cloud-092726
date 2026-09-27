<?php

namespace App\Http\Controllers\Api\V1\Expense;

use App\Http\Controllers\Controller;
use App\Models\AccountingJournalEntry;
use App\Models\AccountingPaymentMethodMapping;
use App\Models\ReferenceValue;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Expense;
use App\Models\ExpenseReversalRequest;
use App\Services\Accounting\ExpenseService;
use App\Services\Foundation\SupportingDocumentService;
use App\Services\MasterData\WorkflowReasonService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExpenseController extends Controller
{
    public function __construct(private readonly ExpenseService $service, private readonly SupportingDocumentService $documents) {}

    public function index(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $query = Expense::query()
            ->where('business_id', $businessId)
            ->with(['branch:id,name,short_code','lines.account:id,code,name,account_type,account_group','paymentAccount:id,code,name','creator:id,name','poster:id,name','payer:id,name','reverser:id,name','reversalRequests.requester:id,name','reversalRequests.reviewer:id,name']);

        if ($from = $request->query('from')) $query->whereDate('expense_date', '>=', $from);
        if ($to = $request->query('to')) $query->whereDate('expense_date', '<=', $to);
        if ($branchId = $request->query('branch_id')) $query->where('branch_id', $branchId);
        if (($status = $request->query('status')) && $status !== 'ALL') $query->where('status', $status);
        if (($payment = $request->query('payment_status')) && $payment !== 'ALL') $query->where('payment_status', $payment);
        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', '%'.$search.'%')
                    ->orWhere('vendor_payee', 'like', '%'.$search.'%')
                    ->orWhere('reference', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%');
            });
        }

        $rows = $query->orderByDesc('expense_date')->orderByDesc('id')->limit(1000)->get();
        if ($fundingAccountId = $request->integer('funding_account_id')) {
            $rows = $rows->filter(function (Expense $expense) use ($fundingAccountId) {
                if ((int) ($expense->payment_account_id ?? 0) === $fundingAccountId) return true;
                return collect($expense->payment_splits ?: [])->contains(function ($split) use ($fundingAccountId) {
                    return (int) ($split['funding_account_id'] ?? $split['cash_account_id'] ?? 0) === $fundingAccountId;
                });
            })->values();
        }
        $posted = $rows->where('status', 'POSTED');

        return response()->json([
            'rows' => $rows->map(fn (Expense $expense) => $this->present($expense))->values(),
            'summary' => [
                'posted_total' => round((float) $posted->sum('total_amount'), 2),
                'paid_total' => round((float) $posted->where('payment_status', 'PAID')->sum('total_amount'), 2),
                'unpaid_total' => round((float) $posted->where('payment_status', 'UNPAID')->sum('total_amount'), 2),
                'draft_count' => $rows->where('status', 'DRAFT')->count(),
            ],
            'options' => $this->options($businessId),
        ]);
    }

    public function show(Request $request, Expense $expense): JsonResponse
    {
        $this->guardBusiness($request, $expense);
        $expense = $expense->load(['branch:id,name,short_code','lines.account:id,code,name,account_type,account_group','paymentAccount:id,code,name','creator:id,name','poster:id,name','payer:id,name','reverser:id,name','reversalRequests.requester:id,name','reversalRequests.reviewer:id,name']);
        $journals = AccountingJournalEntry::query()
            ->where('business_id', $expense->business_id)
            ->where('source_type', 'EXPENSE')
            ->where('source_id', $expense->id)
            ->with('lines')
            ->orderBy('id')
            ->get();

        return response()->json(['expense' => $this->present($expense), 'journals' => $journals]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateExpense($request);
        $this->validateAccounts($request->user()->business_id, $data);
        try {
            $expense = DB::transaction(function () use ($request, $data) {
                $expense = $this->service->create($request->user(), $data);
                $this->documents->claimTokens($request->user(), $data['attachment_tokens'] ?? [], 'expenses', 'EXPENSE', (int) $expense->id, 'EXPENSE_SUPPORT');
                return $expense;
            });
            return response()->json(['expense' => $this->present($expense)], 201);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function update(Request $request, Expense $expense): JsonResponse
    {
        $this->guardBusiness($request, $expense);
        $data = $this->validateExpense($request);
        $this->validateAccounts($request->user()->business_id, $data);
        try {
            $updated = DB::transaction(function () use ($request, $expense, $data) {
                $updated = $this->service->update($request->user(), $expense, $data);
                $this->documents->claimTokens($request->user(), $data['attachment_tokens'] ?? [], 'expenses', 'EXPENSE', (int) $expense->id, 'EXPENSE_SUPPORT');
                return $updated;
            });
            return response()->json(['expense' => $this->present($updated)]);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function post(Request $request, Expense $expense): JsonResponse
    {
        $this->guardBusiness($request, $expense);
        try {
            return response()->json(['expense' => $this->present($this->service->post($request->user(), $expense))]);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function markPaid(Request $request, Expense $expense): JsonResponse
    {
        $this->guardBusiness($request, $expense);
        $data = $request->validate([
            'payment_splits' => ['nullable','array','min:1'],
            'payment_splits.*.funding_account_id' => ['nullable','integer'],
            'payment_splits.*.payment_method_reference_id' => ['nullable','integer'],
            'payment_splits.*.cash_account_id' => ['nullable','integer'],
            'payment_splits.*.payment_method' => ['nullable','string','max:120'],
            'payment_splits.*.amount' => ['required_with:payment_splits','numeric','gt:0'],
            'payment_splits.*.reference' => ['nullable','string','max:120'],
            // Backward-compatible API input. The current UI uses mapped payment_splits.
            'payment_account_id' => ['nullable','integer'],
            'payment_method' => ['nullable','string','max:120'],
            'payment_reference' => ['nullable','string','max:120'],
        ]);

        $splits = $data['payment_splits'] ?? [];
        if (! $splits && ! empty($data['payment_account_id'])) {
            $splits = [[
                'cash_account_id' => (int) $data['payment_account_id'],
                'payment_method' => $data['payment_method'] ?? null,
                'amount' => (float) $expense->total_amount,
                'reference' => $data['payment_reference'] ?? null,
            ]];
        }

        if (! $splits) {
            return response()->json(['message' => 'Add at least one payment method.'], 422);
        }

        try {
            return response()->json(['expense' => $this->present($this->service->markPaid($request->user(), $expense, $splits))]);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function reverse(Request $request, Expense $expense, WorkflowReasonService $reasons): JsonResponse
    {
        $this->guardBusiness($request, $expense);
        $data = $request->validate([
            'reason_reference_id' => ['required','integer'],
            'reason' => ['required','string','min:5','max:1000'],
            'attachment_tokens' => ['sometimes','array','max:10'],
            'attachment_tokens.*' => ['required','uuid','distinct'],
        ]);
        try {
            $reasonReference = $reasons->resolve($request->user(), (int)$data['reason_reference_id'], 'EXPENSE_REVERSAL');
            $reversal = DB::transaction(function () use ($request, $expense, $data, $reasonReference) {
                $reversal = $this->service->requestReversal($request->user(), $expense, $data['reason'], (int)$reasonReference->id);
                $this->documents->claimTokens($request->user(), $data['attachment_tokens'] ?? [], 'expenses', 'EXPENSE_REVERSAL_REQUEST', (int) $reversal->id, 'EXPENSE_REVERSAL_SUPPORT');
                return $reversal;
            });
            return response()->json([
                'message' => 'Expense reversal submitted for approval.',
                'reversal_request' => $this->presentReversalRequest($reversal),
                'expense' => $this->present($expense->fresh()),
            ], 201);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function approveReversal(Request $request, ExpenseReversalRequest $expenseReversalRequest): JsonResponse
    {
        abort_unless((int) $expenseReversalRequest->business_id === (int) $request->user()->business_id, 404);
        $data = $request->validate(['notes' => ['nullable','string','max:2000']]);
        try {
            $expense = $this->service->approveReversal($request->user(), $expenseReversalRequest, $data['notes'] ?? null);
            $approvedRequest = $expenseReversalRequest->fresh(['expense','requester','reviewer','reasonReference','reviewReasonReference']);
            return response()->json(['message' => 'Expense reversal approved and posted.', 'reversal_request' => $this->presentReversalRequest($approvedRequest), 'expense' => $this->present($expense)]);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function rejectReversal(Request $request, ExpenseReversalRequest $expenseReversalRequest, WorkflowReasonService $reasons): JsonResponse
    {
        abort_unless((int) $expenseReversalRequest->business_id === (int) $request->user()->business_id, 404);
        $data = $request->validate(['review_reason_reference_id'=>['required','integer'],'notes' => ['required','string','min:3','max:2000']]);
        try {
            $reviewReason = $reasons->resolve($request->user(), (int)$data['review_reason_reference_id'], 'APPROVAL_REJECTION');
            $row = $this->service->rejectReversal($request->user(), $expenseReversalRequest, $data['notes'], (int)$reviewReason->id);
            return response()->json(['message' => 'Expense reversal request rejected.', 'reversal_request' => $this->presentReversalRequest($row)]);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function uploadAttachment(Request $request, Expense $expense): JsonResponse
    {
        $this->guardBusiness($request, $expense);
        $request->validate(['file' => ['required','file','mimes:pdf,jpg,jpeg,png,webp','max:10240']]);
        try {
            return response()->json(['expense' => $this->present($this->service->storeAttachment($request->user(), $expense, $request->file('file')))]);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function attachment(Request $request, Expense $expense): StreamedResponse
    {
        $this->guardBusiness($request, $expense);
        abort_unless($expense->attachment_disk && $expense->attachment_path, 404);
        abort_unless(Storage::disk($expense->attachment_disk)->exists($expense->attachment_path), 404);
        return Storage::disk($expense->attachment_disk)->response(
            $expense->attachment_path,
            $expense->attachment_name ?: basename($expense->attachment_path),
            ['Content-Type' => $expense->attachment_mime ?: 'application/octet-stream']
        );
    }

    private function validateExpense(Request $request): array
    {
        $businessId = $request->user()->business_id;
        return $request->validate([
            'branch_id' => ['required','integer', Rule::exists('branches', 'id')->where('business_id', $businessId)],
            'expense_date' => ['required','date'],
            'vendor_payee' => ['required','string','max:180'],
            'reference' => ['nullable','string','max:120'],
            'description' => ['nullable','string','max:255'],
            'payment_status' => ['required', Rule::in(['PAID','UNPAID'])],
            'payment_splits' => ['nullable','array'],
            'payment_splits.*.funding_account_id' => ['nullable','integer'],
            'payment_splits.*.payment_method_reference_id' => ['nullable','integer'],
            'payment_splits.*.cash_account_id' => ['nullable','integer'],
            'payment_splits.*.payment_method' => ['nullable','string','max:120'],
            'payment_splits.*.amount' => ['required_with:payment_splits','numeric','gt:0'],
            'payment_splits.*.reference' => ['nullable','string','max:120'],
            // Legacy fields remain accepted for API compatibility; current UI no longer exposes Payment Account.
            'payment_account_id' => ['nullable','integer'],
            'payment_method' => ['nullable','string','max:120'],
            'payment_reference' => ['nullable','string','max:120'],
            'notes' => ['nullable','string','max:2000'],
            'attachment_tokens' => ['sometimes','array','max:10'],
            'attachment_tokens.*' => ['required','uuid','distinct'],
            'lines' => ['required','array','min:1'],
            'lines.*.account_id' => ['required','integer'],
            'lines.*.description' => ['nullable','string','max:255'],
            'lines.*.amount' => ['required','numeric','gt:0','max:999999999999.99'],
        ]);
    }

    private function validateAccounts(int $businessId, array $data): void
    {
        $ids = collect($data['lines'])->pluck('account_id')->unique()->values();
        $validCount = ChartOfAccount::query()
            ->where('business_id', $businessId)
            ->whereIn('id', $ids)
            ->where('active', true)
            ->where('posting_type', 'POSTING')
            ->where(function ($q) {
                $q->whereIn('account_type', ['OPERATING_EXPENSE','COST_OF_SALES'])
                    ->orWhere(function ($asset) {
                        $asset->where('account_type', 'ASSET')->whereNull('system_key')->where('is_cash_account', false);
                    });
            })
            ->count();
        abort_unless($validCount === $ids->count(), 422, 'One or more expense accounts are invalid or unavailable for expense entry.');

        if ($data['payment_status'] === 'PAID') {
            $expenseTotal = round((float) collect($data['lines'])->sum(fn ($line) => (float) $line['amount']), 2);
            $splits = collect($data['payment_splits'] ?? []);
            if ($splits->isEmpty() && ! empty($data['payment_account_id'])) {
                $splits = collect([[
                    'cash_account_id' => (int) $data['payment_account_id'],
                    'payment_method' => $data['payment_method'] ?? null,
                    'amount' => $expenseTotal,
                    'reference' => $data['payment_reference'] ?? null,
                ]]);
                $data['payment_splits'] = $splits->all();
            }
            abort_unless($splits->isNotEmpty(), 422, 'Add at least one payment method for a paid expense.');
            $splitTotal = round((float) $splits->sum(fn ($row) => (float) ($row['amount'] ?? 0)), 2);
            abort_unless(abs($expenseTotal - $splitTotal) <= 0.01, 422, 'Split payment total must equal the expense total.');
            foreach ($splits as $split) {
                $fundingAccountId = (int) ($split['funding_account_id'] ?? $split['cash_account_id'] ?? 0);
                if ($fundingAccountId > 0) {
                    $valid = ChartOfAccount::query()
                        ->where('business_id', $businessId)
                        ->whereKey($fundingAccountId)
                        ->where('active', true)
                        ->where('posting_type', 'POSTING')
                        ->where('is_funding_source', true)
                        ->exists();
                    abort_unless($valid, 422, 'Select a valid active Expense / Supplier Funding Source.');
                    continue;
                }

                $valid = AccountingPaymentMethodMapping::query()
                    ->where('business_id', $businessId)
                    ->where('payment_method_reference_id', (int) ($split['payment_method_reference_id'] ?? 0))
                    ->whereHas('cashAccount', fn ($q) => $q->where('active', true)->where('posting_type','POSTING')->where('is_cash_account', true))
                    ->exists();
                abort_unless($valid, 422, 'Every payment method must be mapped to an active Cash / Payment Account.');
            }
        }
    }

    private function options(int $businessId): array
    {
        $accounts = ChartOfAccount::query()->where('business_id', $businessId)->where('active', true)->orderBy('code')->get();
        return [
            'branches' => Branch::query()->where('business_id', $businessId)->where('status', 'ACTIVE')->orderBy('name')->get(['id','name','short_code']),
            'expense_accounts' => $accounts->filter(function (ChartOfAccount $account) {
                if ($account->posting_type !== 'POSTING') return false;
                if (in_array($account->account_type, ['OPERATING_EXPENSE','COST_OF_SALES'], true)) return true;
                return $account->account_type === 'ASSET' && ! $account->system_key && ! $account->is_cash_account;
            })->values()->map(fn (ChartOfAccount $a) => ['id'=>$a->id,'code'=>$a->code,'name'=>$a->name,'account_type'=>$a->account_type,'account_group'=>$a->account_group]),
            'funding_sources' => $accounts->filter(fn (ChartOfAccount $account) => $account->posting_type === 'POSTING' && $account->is_funding_source)
                ->values()
                ->map(fn (ChartOfAccount $a) => ['id'=>$a->id,'code'=>$a->code,'name'=>$a->name,'account_type'=>$a->account_type,'is_cash_account'=>(bool)$a->is_cash_account]),
            // Legacy mapped methods remain in the response for older clients. Current expense UI uses funding_sources.
            'payment_methods' => AccountingPaymentMethodMapping::query()
                ->where('accounting_payment_method_mappings.business_id', $businessId)
                ->join('reference_values as rv','rv.id','=','accounting_payment_method_mappings.payment_method_reference_id')
                ->join('reference_groups as rg','rg.id','=','rv.reference_group_id')
                ->join('chart_of_accounts as ca','ca.id','=','accounting_payment_method_mappings.cash_account_id')
                ->where('rg.code','PAYMENT_METHOD')->where('rv.active',true)->where('ca.active',true)->where('ca.is_cash_account',true)
                ->orderBy('rv.sort_order')->orderBy('rv.display_value')
                ->get(['rv.id','rv.code','rv.display_value'])
                ->map(fn ($row) => ['id'=>$row->id,'code'=>$row->code,'name'=>$row->display_value])->values(),
        ];
    }

    private function present(Expense $expense): array
    {
        $expense->loadMissing(['branch:id,name,short_code','lines.account:id,code,name,account_type,account_group','paymentAccount:id,code,name','creator:id,name','poster:id,name','payer:id,name','reverser:id,name','reversalRequests.requester:id,name','reversalRequests.reviewer:id,name']);
        return [
            'id' => $expense->id,
            'code' => $expense->code,
            'expense_date' => $expense->expense_date?->toDateString(),
            'branch' => $expense->branch,
            'vendor_payee' => $expense->vendor_payee,
            'reference' => $expense->reference,
            'description' => $expense->description,
            'total_amount' => (float) $expense->total_amount,
            'status' => $expense->status,
            'payment_status' => $expense->payment_status,
            'payment_account' => $expense->paymentAccount,
            'payment_method' => $expense->payment_method,
            'payment_reference' => $expense->payment_reference,
            'payment_splits' => $expense->payment_splits ?: [],
            'paid_at' => $expense->paid_at?->toIso8601String(),
            'attachment' => $expense->attachment_path ? [
                'name' => $expense->attachment_name,
                'mime' => $expense->attachment_mime,
            ] : null,
            'notes' => $expense->notes,
            'posted_at' => $expense->posted_at?->toIso8601String(),
            'reversed_at' => $expense->reversed_at?->toIso8601String(),
            'reversal_reason' => $expense->reversal_reason,
            'created_by' => $expense->creator?->only(['id','name']),
            'posted_by' => $expense->poster?->only(['id','name']),
            'paid_by' => $expense->payer?->only(['id','name']),
            'reversed_by' => $expense->reverser?->only(['id','name']),
            'reversal_request' => ($latest = $expense->reversalRequests->sortByDesc('id')->first()) ? $this->presentReversalRequest($latest) : null,
            'pending_reversal' => ($pending = $expense->reversalRequests->first(fn ($row) => strtoupper((string) $row->status) === 'PENDING')) ? $this->presentReversalRequest($pending) : null,
            'lines' => $expense->lines->map(fn ($line) => [
                'id' => $line->id,
                'line_no' => $line->line_no,
                'account_id' => $line->account_id,
                'account' => $line->account,
                'description' => $line->description,
                'amount' => (float) $line->amount,
            ])->values(),
        ];
    }

    private function presentReversalRequest(ExpenseReversalRequest $row): array
    {
        $row->loadMissing(['requester:id,name','reviewer:id,name','reasonReference','reviewReasonReference']);
        return [
            'id' => (int) $row->id,
            'code' => $row->code,
            'status' => strtoupper((string) $row->status),
            'reason' => $row->reason,
            'reason_category' => $row->reasonReference ? ['id'=>(int)$row->reasonReference->id,'code'=>$row->reasonReference->code,'name'=>$row->reasonReference->display_value] : null,
            'review_reason_category' => $row->reviewReasonReference ? ['id'=>(int)$row->reviewReasonReference->id,'code'=>$row->reviewReasonReference->code,'name'=>$row->reviewReasonReference->display_value] : null,
            'requested_by' => $row->requester?->display_name,
            'reviewed_by' => $row->reviewer?->display_name,
            'reviewed_at' => $row->reviewed_at?->toIso8601String(),
            'review_notes' => $row->review_notes,
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }

    private function guardBusiness(Request $request, Expense $expense): void
    {
        abort_unless($expense->business_id === $request->user()->business_id, 404);
    }
}
