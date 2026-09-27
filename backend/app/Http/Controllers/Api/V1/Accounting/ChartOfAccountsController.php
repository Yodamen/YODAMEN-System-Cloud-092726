<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Models\AccountingJournalLine;
use App\Models\ChartOfAccount;
use App\Models\ReferenceValue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChartOfAccountsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $query = ChartOfAccount::query()->where('business_id', $businessId);

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', '%'.$search.'%')
                    ->orWhere('name', 'like', '%'.$search.'%')
                    ->orWhere('account_group', 'like', '%'.$search.'%');
            });
        }
        if ($type = $request->query('account_type')) {
            $query->where('account_type', $type);
        }
        if (($active = $request->query('active')) !== null && $active !== '' && $active !== 'ALL') {
            $query->where('active', filter_var($active, FILTER_VALIDATE_BOOLEAN));
        }

        $accounts = $query->orderBy('code')->get()->map(fn (ChartOfAccount $account) => $this->present($account));

        return response()->json([
            'accounts' => $accounts,
            'options' => [
                'account_types' => ['ASSET','LIABILITY','EQUITY','INCOME','COST_OF_SALES','OPERATING_EXPENSE'],
                'normal_balances' => ['DEBIT','CREDIT'],
                'posting_types' => ['POSTING','HEADER'],
                'cash_flow_classifications' => ['OPERATING','INVESTING','FINANCING','NON_CASH'],
                'financial_statement_groups' => ReferenceValue::query()->where('active',true)->whereHas('group', fn($q)=>$q->where('business_id',$businessId)->where('code','FINANCIAL_STATEMENT_GROUP'))->orderBy('sort_order')->orderBy('display_value')->get(['code','display_value'])->map(fn($row)=>['code'=>$row->code,'name'=>$row->display_value])->values(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $data = $this->validated($request, $businessId);
        $data = $this->normalizeFundingSource($data);
        $account = ChartOfAccount::query()->create([
            'business_id' => $businessId,
            ...$data,
            'system_key' => null,
            'active' => true,
        ]);

        return response()->json(['account' => $this->present($account)], 201);
    }

    public function update(Request $request, ChartOfAccount $chartOfAccount): JsonResponse
    {
        $this->guardBusiness($request, $chartOfAccount);
        $data = $this->normalizeFundingSource($this->validated($request, $chartOfAccount->business_id, $chartOfAccount->id));

        $used = $chartOfAccount->expenseLines()->exists()
            || AccountingJournalLine::query()->where('account_id', $chartOfAccount->id)->exists();

        if ($chartOfAccount->system_key) {
            $data['code'] = $chartOfAccount->code;
            $data['account_type'] = $chartOfAccount->account_type;
            $data['normal_balance'] = $chartOfAccount->normal_balance;
            $data['posting_type'] = $chartOfAccount->posting_type;
            $data['is_cash_account'] = $chartOfAccount->is_cash_account;
            if (in_array($chartOfAccount->system_key, ['CASH_ON_HAND', 'CASH_WITH_MANAGER', 'POS_CLEARING', 'OWNERS_CAPITAL', 'LOANS_PAYABLE_OWNER'], true)) {
                $data['is_funding_source'] = false;
            }
        } elseif ($used) {
            foreach (['code','account_type','normal_balance','posting_type'] as $field) {
                if ((string) $data[$field] !== (string) $chartOfAccount->{$field}) {
                    return response()->json(['message' => 'Account code/type/balance cannot be changed after the account has been used.'], 422);
                }
            }
        }

        $chartOfAccount->update($data);
        return response()->json(['account' => $this->present($chartOfAccount->fresh())]);
    }

    public function toggle(Request $request, ChartOfAccount $chartOfAccount): JsonResponse
    {
        $this->guardBusiness($request, $chartOfAccount);
        if ($chartOfAccount->system_key && $chartOfAccount->active) {
            return response()->json(['message' => 'System-mapped accounts must remain active.'], 422);
        }
        $chartOfAccount->update(['active' => ! $chartOfAccount->active]);
        return response()->json(['account' => $this->present($chartOfAccount->fresh())]);
    }

    private function validated(Request $request, int $businessId, ?int $ignoreId = null): array
    {
        return $request->validate([
            'code' => ['required','string','max:40', Rule::unique('chart_of_accounts', 'code')->where('business_id', $businessId)->ignore($ignoreId)],
            'name' => ['required','string','max:180'],
            'account_type' => ['required', Rule::in(['ASSET','LIABILITY','EQUITY','INCOME','COST_OF_SALES','OPERATING_EXPENSE'])],
            'account_group' => ['nullable','string','max:100'],
            'financial_statement_group' => ['nullable','string','max:80', Rule::exists('reference_values','code')->where(fn($q) => $q->whereIn('reference_group_id', \App\Models\ReferenceGroup::query()->where('business_id',$businessId)->where('code','FINANCIAL_STATEMENT_GROUP')->pluck('id')))],
            'normal_balance' => ['required', Rule::in(['DEBIT','CREDIT'])],
            'posting_type' => ['required', Rule::in(['POSTING','HEADER'])],
            'cash_flow_classification' => ['nullable', Rule::in(['OPERATING','INVESTING','FINANCING','NON_CASH'])],
            'is_opex' => ['required','boolean'],
            'is_cash_account' => ['required','boolean'],
            'is_funding_source' => ['required','boolean'],
            'notes' => ['nullable','string','max:1000'],
        ]);
    }

    private function normalizeFundingSource(array $data): array
    {
        if (! empty($data['is_funding_source'])) {
            abort_unless(($data['posting_type'] ?? null) === 'POSTING', 422, 'Funding sources must be Posting accounts.');
            abort_unless(in_array($data['account_type'] ?? null, ['ASSET','LIABILITY','EQUITY'], true), 422, 'Funding sources must be Asset, Liability, or Equity accounts.');
        }

        return $data;
    }

    private function guardBusiness(Request $request, ChartOfAccount $account): void
    {
        abort_unless($account->business_id === $request->user()->business_id, 404);
    }

    private function present(ChartOfAccount $account): array
    {
        return [
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'account_type' => $account->account_type,
            'account_group' => $account->account_group,
            'financial_statement_group' => $account->financial_statement_group,
            'normal_balance' => $account->normal_balance,
            'posting_type' => $account->posting_type,
            'cash_flow_classification' => $account->cash_flow_classification,
            'is_opex' => $account->is_opex,
            'is_cash_account' => $account->is_cash_account,
            'is_funding_source' => $account->is_funding_source,
            'system_key' => $account->system_key,
            'active' => $account->active,
            'notes' => $account->notes,
        ];
    }
}
