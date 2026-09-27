<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Operations\PreviewCustomerAudienceRequest;
use App\Http\Requests\Api\V1\Operations\SaveCustomerRequest;
use App\Models\Customer;
use App\Models\DiscountRule;
use App\Models\PackageEnrollment;
use App\Models\ReferenceGroup;
use App\Models\Sale;
use App\Models\Visit;
use App\Services\Discounts\DiscountRuleService;
use App\Services\Customers\CustomerClassificationService;
use App\Services\Operations\CustomerAudienceService;
use App\Services\Operations\CustomerService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerService $customers,
        private readonly DiscountRuleService $discountRules,
        private readonly CustomerAudienceService $audiences,
        private readonly CustomerClassificationService $classification,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Customer::query()
            ->with(['gender:id,code,display_value', 'leadSource:id,code,display_value'])
            ->where('business_id', $user->business_id);

        if ($search = trim((string) $request->query('search', ''))) {
            $normalized = $this->customers->normalizeMobile($search);
            $query->where(function ($builder) use ($search, $normalized) {
                $builder->where('code', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhereRaw("concat(first_name, ' ', last_name) like ?", ["%{$search}%"])
                    ->orWhere('mobile_number', 'like', "%{$search}%");
                if ($normalized !== '') {
                    $builder->orWhere('mobile_normalized', 'like', "%{$normalized}%");
                }
            });
        }

        $status = strtoupper(trim((string) $request->query('status', '')));
        if (in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
            $query->where('status', $status);
        }

        $rows = $query->orderByDesc('registration_date')->orderByDesc('id')->get();
        $metrics = $this->metricsForCustomers($rows, (int) $user->business_id);

        $promoFilter = null;
        $promoCode = trim((string) $request->query('promo_code', ''));
        if ($promoCode !== '') {
            $normalizedPromoCode = $this->discountRules->normalizeCode($promoCode);
            $candidates = $this->discountRules->activeCandidatesForCustomerFilter(
                (int) $user->business_id,
                $normalizedPromoCode,
                CarbonImmutable::now(config('yodamen.business_timezone', 'Asia/Manila')),
            );

            $rows = $rows
                ->filter(fn (Customer $customer) => $this->discountRules->customerMatchesCandidates(
                    $customer,
                    $candidates,
                    $metrics[$customer->id] ?? [],
                ))
                ->values();

            $promoFilter = [
                'code' => $normalizedPromoCode,
                'active_rule_count' => $candidates->count(),
                'qualified_customer_count' => $rows->count(),
                'found' => $candidates->isNotEmpty(),
                'message' => $candidates->isEmpty()
                    ? 'No active promo rule is currently effective for this code.'
                    : 'Customer eligibility criteria applied. Branch, service, category, package, product, and other checkout-specific restrictions are still validated at Checkout.',
            ];
        }

        return response()->json([
            'customers' => $rows->map(fn (Customer $customer) => $this->resource($customer, $metrics[$customer->id] ?? [])),
            'promo_filter' => $promoFilter,
            'references' => [
                'genders' => $this->referenceOptions($user->business_id, 'GENDER'),
                'lead_sources' => $this->referenceOptions($user->business_id, 'LEAD_SOURCE'),
                'promo_codes' => $this->promoOptions((int) $user->business_id),
            ],
        ]);
    }

    public function audienceOptions(Request $request): JsonResponse
    {
        return response()->json([
            'options' => $this->audiences->options((int) $request->user()->business_id),
        ]);
    }

    public function audiencePreview(PreviewCustomerAudienceRequest $request): JsonResponse
    {
        return response()->json(
            $this->audiences->preview((int) $request->user()->business_id, $request->validated())
        );
    }

    public function show(Request $request, Customer $customer): JsonResponse
    {
        $this->assertOwned($request, $customer);
        $customer->load(['gender:id,code,display_value', 'leadSource:id,code,display_value']);
        $metrics = $this->metricsForCustomers(collect([$customer]), (int) $request->user()->business_id);
        $customerMetrics = $metrics[$customer->id] ?? [];

        return response()->json([
            'customer' => $this->resource($customer, $customerMetrics),
            'profile' => $this->profileResource($customer, $customerMetrics, (int) $request->user()->business_id),
        ]);
    }

    public function store(SaveCustomerRequest $request): JsonResponse
    {
        try {
            $customer = $this->customers->save($request->user(), $request->validated());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        return response()->json(['customer' => $this->resource($customer)], 201);
    }

    public function update(SaveCustomerRequest $request, Customer $customer): JsonResponse
    {
        try {
            $customer = $this->customers->save($request->user(), $request->validated(), $customer);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        return response()->json(['customer' => $this->resource($customer)]);
    }

    public function toggle(Request $request, Customer $customer): JsonResponse
    {
        $customer = $this->customers->toggle($request->user(), $customer);
        return response()->json(['customer' => $this->resource($customer)]);
    }

    private function assertOwned(Request $request, Customer $customer): void
    {
        if ($customer->business_id !== $request->user()->business_id) {
            abort(404);
        }
    }

    private function promoOptions(int $businessId): array
    {
        $today = CarbonImmutable::now(config('yodamen.business_timezone', 'Asia/Manila'))->toDateString();

        return DiscountRule::query()
            ->where('business_id', $businessId)
            ->orderBy('discount_code')
            ->orderByDesc('priority')
            ->get(['discount_code', 'status', 'effective_from', 'effective_until'])
            ->groupBy(fn (DiscountRule $rule) => strtoupper(trim((string) $rule->discount_code)))
            ->filter(fn (Collection $rules, $code) => trim((string) $code) !== '')
            ->map(function (Collection $rules, $code) use ($today) {
                $code = (string) $code;
                $active = $rules->contains(function (DiscountRule $rule) use ($today) {
                    $statusActive = strtoupper((string) $rule->status) === 'ACTIVE';
                    $started = ! $rule->effective_from || $rule->effective_from->format('Y-m-d') <= $today;
                    $notExpired = ! $rule->effective_until || $rule->effective_until->format('Y-m-d') >= $today;

                    return $statusActive && $started && $notExpired;
                });

                return [
                    'code' => $code,
                    'active' => $active,
                ];
            })
            ->sortBy('code')
            ->values()
            ->all();
    }

    private function referenceOptions(int $businessId, string $groupCode): array
    {
        $group = ReferenceGroup::query()
            ->where('business_id', $businessId)
            ->where('code', $groupCode)
            ->with(['values' => fn ($query) => $query->where('active', true)->orderBy('sort_order')->orderBy('display_value')])
            ->first();

        return $group?->values->map(fn ($value) => [
            'code' => $value->code,
            'value' => $value->display_value,
        ])->values()->all() ?? [];
    }

    private function metricsForCustomers(Collection $customers, int $businessId): array
    {
        $customerIds = $customers->pluck('id')->map(fn ($id) => (int) $id)->filter()->values();
        if ($customerIds->isEmpty()) {
            return [];
        }

        $visits = Visit::query()
            ->where('business_id', $businessId)
            ->whereIn('customer_id', $customerIds)
            ->whereNotIn('status', ['CANCELLED', 'CANCELED', 'NO_SHOW'])
            ->get(['id', 'customer_id', 'visit_date', 'status']);

        $sales = Sale::query()
            ->where('business_id', $businessId)
            ->whereIn('customer_id', $customerIds)
            ->whereNotNull('financial_finalized_at')
            ->where('status', '<>', 'REVERSED')
            ->with(['payments' => fn ($query) => $query
                ->where('status', 'RECEIVED')
                ->where('payment_type', '!=', 'TIP')])
            ->get();

        $today = now(config('yodamen.business_timezone', 'Asia/Manila'))->startOfDay();
        $metrics = [];

        foreach ($customerIds as $customerId) {
            $customerVisits = $visits->where('customer_id', $customerId);
            $lastVisit = $customerVisits->sortByDesc(fn (Visit $visit) => $visit->visit_date?->format('Y-m-d') ?? '')->first()?->visit_date;
            $completedVisits = $customerVisits->filter(fn (Visit $visit) => strtoupper((string) $visit->status) === 'COMPLETED')->count();

            $lifetimeValue = 0.0;
            foreach ($sales->where('customer_id', $customerId) as $sale) {
                $received = (float) $sale->payments->sum(fn ($payment) => (float) $payment->amount);
                $saleValue = (float) ($sale->effective_net_amount ?? $sale->net_amount ?? $sale->gross_amount);
                $lifetimeValue += min($saleValue, $received);
            }

            $metrics[$customerId] = [
                'last_visit' => $lastVisit?->format('Y-m-d'),
                'days_since_last_visit' => $lastVisit ? (int) $lastVisit->startOfDay()->diffInDays($today) : null,
                'lifetime_value' => round($lifetimeValue, 2),
                'total_completed_visits' => $completedVisits,
            ];
        }

        return $metrics;
    }


    private function profileResource(Customer $customer, array $metrics, int $businessId): array
    {
        $sales = Sale::query()
            ->where('business_id', $businessId)
            ->where('customer_id', $customer->id)
            ->with([
                'branch:id,code,name,address',
                'booking:id,code',
                'items:id,sale_id,item_type,item_name_snapshot,quantity,unit_price,line_total,net_line_total,status',
                'payments' => fn ($query) => $query->where('status', 'RECEIVED')->orderByDesc('payment_date')->orderByDesc('id'),
            ])
            ->orderByDesc('sale_date')
            ->orderByDesc('id')
            ->get();

        $outstanding = round((float) $sales->sum(fn (Sale $sale) => max(0, (float) $sale->outstanding_balance)), 2);
        $paid = round((float) $sales->sum(fn (Sale $sale) => (float) $sale->payments->sum('amount')), 2);

        $transactions = $sales->map(function (Sale $sale) {
            $items = $sale->items->map(fn ($item) => [
                'id' => $item->id,
                'type' => strtoupper((string) $item->item_type),
                'name' => $item->item_name_snapshot,
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'amount' => (float) ($item->net_line_total ?? $item->line_total),
                'status' => $item->status,
            ])->values();

            $types = $items->pluck('type')->unique();
            $saleType = $types->every(fn ($type) => $type === 'PRODUCT')
                ? 'Retail'
                : ($types->contains('PRODUCT') ? 'Mixed' : 'Service');

            return [
                'id' => $sale->id,
                'code' => $sale->code,
                'date' => $sale->sale_date?->toIso8601String(),
                'sale_type' => $saleType,
                'booking_code' => $sale->booking?->code,
                'branch' => $sale->branch?->name,
                'branch_location' => $sale->branch?->address ?: $sale->branch?->name,
                'gross_amount' => (float) $sale->gross_amount,
                'discount_amount' => (float) $sale->discount_amount,
                'net_amount' => (float) $sale->net_amount,
                'paid_amount' => round((float) $sale->payments->sum('amount'), 2),
                'outstanding_balance' => (float) $sale->outstanding_balance,
                'status' => $sale->status,
                'items' => $items,
                'payments' => $sale->payments->map(fn ($payment) => [
                    'code' => $payment->code,
                    'amount' => (float) $payment->amount,
                    'method' => $payment->payment_method_snapshot,
                    'reference_no' => $payment->reference_no,
                    'date' => $payment->payment_date?->toIso8601String(),
                ])->values(),
            ];
        })->values();

        $serviceBalances = PackageEnrollment::query()
            ->where('business_id', $businessId)
            ->where('customer_id', $customer->id)
            ->whereNotIn('status', ['CANCELLED', 'VOIDED'])
            ->with('serviceCredit')
            ->orderByDesc('created_at')
            ->get()
            ->map(function (PackageEnrollment $enrollment) {
                $credit = $enrollment->serviceCredit;
                $purchased = (int) $enrollment->total_sessions;
                $used = $credit
                    ? (int) $credit->sessions_used
                    : min($purchased, (int) $enrollment->sessions_completed_before_activation);
                $remaining = $credit
                    ? (int) $credit->sessions_remaining
                    : max(0, $purchased - $used);

                return [
                    'id' => $enrollment->id,
                    'code' => $enrollment->code,
                    'service_name' => $enrollment->service_name_snapshot,
                    'purchased' => $purchased,
                    'used' => $used,
                    'remaining' => $remaining,
                    'status' => $enrollment->status,
                    'expiry_date' => $enrollment->expiry_date?->format('Y-m-d'),
                ];
            })
            ->filter(fn (array $row) => $row['remaining'] > 0 || strtoupper((string) $row['status']) !== 'COMPLETED')
            ->values();

        $completedVisits = (int) ($metrics['total_completed_visits'] ?? 0);
        $lifetimeValue = (float) ($metrics['lifetime_value'] ?? 0);
        $type = $this->classification->typeFor($businessId, $completedVisits);
        $class = $this->classification->classFor($businessId, $lifetimeValue);

        return [
            'summary' => [
                'customer_type' => $type['name'],
                'customer_type_code' => $type['code'],
                'customer_type_color' => $type['color'],
                'customer_class' => $class['name'] ?? 'Unclassified',
                'customer_class_code' => $class['code'] ?? null,
                'completed_visits' => $completedVisits,
                'lifetime_value' => $lifetimeValue,
                'total_paid' => $paid,
                'outstanding_balance' => $outstanding,
                'active_service_balances' => $serviceBalances->sum('remaining'),
                'transaction_count' => $transactions->count(),
                'last_visit' => $metrics['last_visit'] ?? null,
            ],
            'transactions' => $transactions,
            'service_balances' => $serviceBalances,
        ];
    }

    private function resource(Customer $customer, array $metrics = []): array
    {
        $completedVisits = (int) ($metrics['total_completed_visits'] ?? 0);
        $lifetimeValue = (float) ($metrics['lifetime_value'] ?? 0);
        $type = $this->classification->typeFor((int) $customer->business_id, $completedVisits);
        $class = $this->classification->classFor((int) $customer->business_id, $lifetimeValue);

        return [
            'id' => $customer->id,
            'code' => $customer->code,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'full_name' => $customer->full_name,
            'mobile_number' => $customer->mobile_number,
            'email' => $customer->email,
            'birthday' => $customer->birthday?->format('Y-m-d'),
            'birthday_month' => $customer->birthday?->month,
            'gender_code' => $customer->gender?->code,
            'gender' => $customer->gender?->display_value,
            'address' => $customer->address,
            'lead_source_code' => $customer->leadSource?->code,
            'lead_source' => $customer->leadSource?->display_value,
            'registration_date' => $customer->registration_date?->format('Y-m-d'),
            'status' => $customer->status,
            'notes' => $customer->notes,
            'last_visit' => $metrics['last_visit'] ?? null,
            'days_since_last_visit' => $metrics['days_since_last_visit'] ?? null,
            'lifetime_value' => $lifetimeValue,
            'total_completed_visits' => $completedVisits,
            'segment' => $type['name'],
            'customer_type' => $type['name'],
            'customer_type_code' => $type['code'],
            'customer_type_color' => $type['color'],
            'customer_class' => $class['name'] ?? 'Unclassified',
            'customer_class_code' => $class['code'] ?? null,
            'metrics_pending' => false,
            'created_at' => $customer->created_at?->toIso8601String(),
            'updated_at' => $customer->updated_at?->toIso8601String(),
        ];
    }
}
