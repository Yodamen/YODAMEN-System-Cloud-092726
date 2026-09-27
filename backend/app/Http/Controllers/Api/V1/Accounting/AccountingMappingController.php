<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Models\AccountingPaymentMethodMapping;
use App\Models\AccountingProductMapping;
use App\Models\ChartOfAccount;
use App\Models\RetailProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AccountingMappingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;

        $accounts = ChartOfAccount::query()
            ->where('business_id', $businessId)
            ->where('active', true)
            ->where('posting_type', 'POSTING')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'system_key', 'is_cash_account', 'is_funding_source', 'account_type', 'account_group']);

        $accountsByKey = $accounts
            ->filter(fn (ChartOfAccount $account) => filled($account->system_key))
            ->keyBy(fn (ChartOfAccount $account) => strtoupper((string) $account->system_key));

        $productMappings = RetailProduct::query()
            ->where('business_id', $businessId)
            ->where('status', 'ACTIVE')
            ->orderBy('name')
            ->get()
            ->map(function (RetailProduct $product) use ($businessId, $accountsByKey) {
                $mapping = AccountingProductMapping::query()
                    ->where('business_id', $businessId)
                    ->where('retail_product_id', $product->id)
                    ->first();

                $defaults = $this->defaultProductAccountIds($product, $accountsByKey);

                return [
                    'retail_product_id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'category' => $product->category,
                    'retail_available' => (bool) $product->retail_available,
                    'inventory_account_id' => $mapping?->inventory_account_id ?? $defaults['inventory_account_id'],
                    'service_cost_account_id' => $mapping?->service_cost_account_id ?? $defaults['service_cost_account_id'],
                    'retail_cost_account_id' => $mapping?->retail_cost_account_id ?? $defaults['retail_cost_account_id'],
                    'mapping_saved' => (bool) $mapping,
                ];
            })
            ->values();

        $methods = DB::table('reference_values as rv')
            ->join('reference_groups as rg', 'rg.id', '=', 'rv.reference_group_id')
            ->where('rg.business_id', $businessId)
            ->where('rg.code', 'PAYMENT_METHOD')
            ->where('rv.active', true)
            ->orderBy('rv.sort_order')
            ->orderBy('rv.display_value')
            ->get(['rv.id', 'rv.code', 'rv.display_value']);

        $paymentMappings = $methods->map(function ($method) use ($businessId) {
            $mapping = AccountingPaymentMethodMapping::query()
                ->where('business_id', $businessId)
                ->where('payment_method_reference_id', $method->id)
                ->with('cashAccount:id,system_key,is_funding_source')
                ->first();

            $cashAccount = $mapping?->cashAccount;

            return [
                'payment_method_reference_id' => $method->id,
                'code' => $method->code,
                'name' => $method->display_value,
                'cash_account_id' => $mapping?->cash_account_id,
                'cash_account_system_key' => $cashAccount?->system_key,
                'customer_facing' => $mapping ? (bool) $mapping->customer_facing : true,
                // Operating-fund eligibility belongs to the mapped COA account so the
                // same bank/e-wallet rule is honored by Expenses, AP, Payroll, etc.
                'operating_fund_source' => $cashAccount ? (bool) $cashAccount->is_funding_source : false,
                'mapping_saved' => (bool) $mapping,
            ];
        })->values();

        return response()->json([
            'accounts' => $accounts,
            'product_mappings' => $productMappings,
            'payment_mappings' => $paymentMappings,
            'summary' => [
                'active_products' => $productMappings->count(),
                'payment_methods' => $paymentMappings->count(),
                'cash_accounts' => $accounts->where('is_cash_account', true)->count(),
                'unmapped_payment_methods' => $paymentMappings->whereNull('cash_account_id')->count(),
            ],
        ]);
    }

    public function updateProduct(Request $request, int $productId): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;
        RetailProduct::query()->where('business_id', $businessId)->whereKey($productId)->firstOrFail();

        $data = $request->validate([
            'inventory_account_id' => ['required', 'integer'],
            'service_cost_account_id' => ['required', 'integer'],
            'retail_cost_account_id' => ['required', 'integer'],
        ]);

        foreach ($data as $accountId) {
            ChartOfAccount::query()
                ->where('business_id', $businessId)
                ->whereKey($accountId)
                ->where('active', true)
                ->where('posting_type', 'POSTING')
                ->firstOrFail();
        }

        $row = AccountingProductMapping::query()->updateOrCreate(
            ['business_id' => $businessId, 'retail_product_id' => $productId],
            $data,
        );

        return response()->json(['mapping' => $row]);
    }

    public function updatePaymentMethod(Request $request, int $methodId): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;
        $exists = DB::table('reference_values as rv')
            ->join('reference_groups as rg', 'rg.id', '=', 'rv.reference_group_id')
            ->where('rg.business_id', $businessId)
            ->where('rg.code', 'PAYMENT_METHOD')
            ->where('rv.id', $methodId)
            ->where('rv.active', true)
            ->exists();
        abort_unless($exists, 404);

        $data = $request->validate([
            'cash_account_id' => ['required', 'integer'],
            'customer_facing' => ['sometimes', 'boolean'],
            'operating_fund_source' => ['sometimes', 'boolean'],
        ]);

        $cashAccount = ChartOfAccount::query()
            ->where('business_id', $businessId)
            ->whereKey($data['cash_account_id'])
            ->where('active', true)
            ->where('posting_type', 'POSTING')
            ->where('is_cash_account', true)
            ->firstOrFail();

        // Cash with Manager is a custody/transit account managed by Cash Handling,
        // never a selectable payment/disbursement method. Petty Cash is allowed here
        // because it can be an operating fund source while remaining hidden from customers.
        abort_if($cashAccount->system_key === 'CASH_WITH_MANAGER', 422, 'Cash with Manager is controlled by Cash Handling and cannot be mapped as a payment method.');

        $existing = AccountingPaymentMethodMapping::query()
            ->where('business_id', $businessId)
            ->where('payment_method_reference_id', $methodId)
            ->first();

        $customerFacing = array_key_exists('customer_facing', $data)
            ? (bool) $data['customer_facing']
            : ($existing ? (bool) $existing->customer_facing : true);

        // Petty Cash must never become a customer tender even if a user accidentally
        // checks the customer-payment column. It remains available for internal funding.
        if ($cashAccount->system_key === 'PETTY_CASH') {
            $customerFacing = false;
        }

        $operatingFundSource = array_key_exists('operating_fund_source', $data)
            ? (bool) $data['operating_fund_source']
            : (bool) $cashAccount->is_funding_source;

        // Cash collections waiting for deposit are intentionally protected from
        // ordinary operating disbursements. Cash Handling owns the transfer out.
        if (in_array($cashAccount->system_key, ['CASH_ON_HAND', 'CASH_WITH_MANAGER', 'POS_CLEARING'], true)) {
            $operatingFundSource = false;
        }

        if ((bool) $cashAccount->is_funding_source !== $operatingFundSource) {
            $cashAccount->update(['is_funding_source' => $operatingFundSource]);
        }

        $row = AccountingPaymentMethodMapping::query()->updateOrCreate(
            ['business_id' => $businessId, 'payment_method_reference_id' => $methodId],
            [
                'cash_account_id' => $data['cash_account_id'],
                'customer_facing' => $customerFacing,
            ],
        );

        return response()->json([
            'mapping' => $row,
            'operating_fund_source' => $operatingFundSource,
        ]);
    }

    private function defaultProductAccountIds(RetailProduct $product, Collection $accountsByKey): array
    {
        $category = strtoupper((string) ($product->category ?? ''));

        if ($product->retail_available) {
            $inventoryKey = 'INVENTORY_RETAIL_PRODUCTS';
            $serviceCostKey = 'COS_SERVICE_CONSUMABLES';
        } elseif (str_contains($category, 'BARBER') || str_contains($category, 'CONSUMABLE')) {
            $inventoryKey = 'INVENTORY_BARBER_CONSUMABLES';
            $serviceCostKey = 'COS_SERVICE_CONSUMABLES';
        } else {
            $inventoryKey = 'INVENTORY_TREATMENT_CHEMICALS';
            $serviceCostKey = 'COS_SERVICE_CHEMICALS';
        }

        return [
            'inventory_account_id' => $accountsByKey->get($inventoryKey)?->id,
            'service_cost_account_id' => $accountsByKey->get($serviceCostKey)?->id,
            'retail_cost_account_id' => $accountsByKey->get('COS_RETAIL_PRODUCTS')?->id,
        ];
    }
}
