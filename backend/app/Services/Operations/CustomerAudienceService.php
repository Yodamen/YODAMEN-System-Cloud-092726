<?php

namespace App\Services\Operations;

use App\Models\Customer;
use App\Models\DiscountRule;
use App\Models\ReferenceGroup;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Service;
use App\Models\Visit;
use App\Models\VisitServiceLine;
use App\Services\Customers\CustomerClassificationService;
use App\Services\Discounts\DiscountRuleService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;

class CustomerAudienceService
{
    public function __construct(
        private readonly DiscountRuleService $discountRules,
        private readonly CustomerClassificationService $classification,
    ) {
    }

    public function options(int $businessId): array
    {
        $services = Service::query()
            ->where('business_id', $businessId)
            ->with('category:id,code,display_value')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'service_category_reference_id', 'number_of_sessions'])
            ->map(fn (Service $service) => [
                'id' => $service->id,
                'code' => $service->code,
                'name' => $service->name,
                'category_id' => $service->service_category_reference_id,
                'category' => $service->category?->display_value,
                'is_package' => (int) $service->number_of_sessions > 1,
            ])->values();

        $categories = $this->referenceOptions($businessId, 'SERVICE_CATEGORY');
        $leadSources = $this->referenceOptions($businessId, 'LEAD_SOURCE');
        $promoCodes = DiscountRule::query()
            ->where('business_id', $businessId)
            ->where('status', 'ACTIVE')
            ->whereDate('effective_from', '<=', now()->toDateString())
            ->where(function ($query) {
                $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', now()->toDateString());
            })
            ->orderBy('discount_code')
            ->pluck('discount_code')
            ->unique()
            ->values();

        $customerTypes = collect($this->classification->typeOptions($businessId))
            ->map(fn (array $row) => [
                'value' => $row['code'],
                'label' => $row['name'],
                'color' => $row['color'] ?? '#343434',
                'min_completed_visits' => (int) ($row['min_completed_visits'] ?? 0),
            ]);
        if (! $customerTypes->contains(fn (array $row) => strtoupper((string) $row['value']) === 'UNCLASSIFIED')) {
            $customerTypes->push([
                'value' => 'UNCLASSIFIED',
                'label' => 'Unclassified',
                'color' => '#8A8A8A',
                'min_completed_visits' => null,
            ]);
        }
        $customerTypes = $customerTypes->values();

        $customerClasses = collect($this->classification->classOptions($businessId))
            ->map(fn (array $row) => [
                'value' => $row['code'],
                'label' => $row['name'],
                'min_lifetime_value' => (float) ($row['min_lifetime_value'] ?? 0),
            ]);
        if (! $customerClasses->contains(fn (array $row) => strtoupper((string) $row['value']) === 'UNCLASSIFIED')) {
            $customerClasses->push([
                'value' => 'UNCLASSIFIED',
                'label' => 'Unclassified',
                'min_lifetime_value' => null,
            ]);
        }
        $customerClasses = $customerClasses->values();

        return [
            'services' => $services,
            'service_categories' => $categories,
            'lead_sources' => $leadSources,
            'promo_codes' => $promoCodes,
            'customer_statuses' => ['ACTIVE', 'INACTIVE'],
            'customer_types' => $customerTypes,
            'customer_classes' => $customerClasses,
            'birth_months' => collect(range(1, 12))->map(fn ($month) => [
                'value' => $month,
                'label' => CarbonImmutable::create(2000, $month, 1)->format('F'),
            ])->values(),
        ];
    }

    public function preview(int $businessId, array $definition): array
    {
        $customers = Customer::query()
            ->where('business_id', $businessId)
            ->with(['leadSource:id,code,display_value'])
            ->orderBy('id')
            ->get();

        if ($customers->isEmpty()) {
            return ['customer_ids' => [], 'matched_count' => 0, 'total_customers' => 0];
        }

        $customerIds = $customers->pluck('id')->map(fn ($id) => (int) $id)->values();
        $context = $this->buildContext($businessId, $customerIds, $definition);
        $rootMatch = strtoupper((string) $definition['match']);

        $matched = $customers->filter(function (Customer $customer) use ($definition, $rootMatch, $context) {
            $groupResults = collect($definition['groups'])->map(function (array $group) use ($customer, $context) {
                $conditionResults = collect($group['conditions'])->map(
                    fn (array $condition) => $this->matchesCondition($customer, $condition, $context)
                );

                return strtoupper((string) $group['match']) === 'ANY'
                    ? $conditionResults->contains(true)
                    : $conditionResults->every(fn ($result) => $result === true);
            });

            return $rootMatch === 'ANY'
                ? $groupResults->contains(true)
                : $groupResults->every(fn ($result) => $result === true);
        })->values();

        return [
            'customer_ids' => $matched->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'matched_count' => $matched->count(),
            'total_customers' => $customers->count(),
        ];
    }

    private function buildContext(int $businessId, Collection $customerIds, array $definition): array
    {
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

        $visitIds = $visits->pluck('id');
        $serviceEvents = VisitServiceLine::query()
            ->whereIn('visit_id', $visitIds)
            ->where('status', 'COMPLETED')
            ->with(['visit:id,customer_id,visit_date,status', 'service:id,service_category_reference_id'])
            ->get()
            ->groupBy(fn (VisitServiceLine $line) => (int) $line->visit?->customer_id)
            ->map(fn (Collection $rows) => $rows->map(fn (VisitServiceLine $line) => [
                'service_id' => (int) $line->service_id,
                'category_id' => (int) ($line->service?->service_category_reference_id ?? 0),
                'date' => $line->visit?->visit_date?->toDateString(),
            ])->values());

        $saleIds = $sales->pluck('id');
        $packageEvents = SaleItem::query()
            ->whereIn('sale_id', $saleIds)
            ->where('item_type', 'PACKAGE')
            ->whereNotIn('correction_status', ['REMOVED', 'REVERSED'])
            ->whereNotNull('financial_finalized_at')
            ->with('sale:id,customer_id,sale_date,status')
            ->get()
            ->filter(fn (SaleItem $item) => ! in_array(strtoupper((string) $item->sale?->status), ['CANCELLED', 'CANCELED', 'VOID', 'REFUNDED', 'REVERSED'], true))
            ->groupBy(fn (SaleItem $item) => (int) $item->sale?->customer_id)
            ->map(fn (Collection $rows) => $rows->map(fn (SaleItem $item) => [
                'service_id' => (int) $item->service_id,
                'date' => $item->sale?->sale_date?->toDateString(),
            ])->values());

        $today = now(config('yodamen.business_timezone', 'Asia/Manila'))->startOfDay();
        $metrics = [];
        foreach ($customerIds as $customerId) {
            $customerVisits = $visits->where('customer_id', $customerId);
            $lastVisit = $customerVisits->sortByDesc(fn (Visit $visit) => $visit->visit_date?->format('Y-m-d') ?? '')->first()?->visit_date;
            $lifetimeValue = 0.0;
            foreach ($sales->where('customer_id', $customerId) as $sale) {
                $received = (float) $sale->payments->sum(fn ($payment) => (float) $payment->amount);
                $saleValue = (float) ($sale->effective_net_amount ?? $sale->net_amount ?? $sale->gross_amount);
                $lifetimeValue += min($saleValue, $received);
            }
            $completedVisits = $customerVisits->filter(
                fn (Visit $visit) => strtoupper((string) $visit->status) === 'COMPLETED'
            )->count();
            $roundedLifetimeValue = round($lifetimeValue, 2);
            $type = $this->classification->typeFor($businessId, $completedVisits);
            $class = $this->classification->classFor($businessId, $roundedLifetimeValue);

            $metrics[(int) $customerId] = [
                'last_visit' => $lastVisit?->format('Y-m-d'),
                'days_since_last_visit' => $lastVisit ? (int) $lastVisit->startOfDay()->diffInDays($today) : null,
                'lifetime_value' => $roundedLifetimeValue,
                'total_completed_visits' => $completedVisits,
                'customer_type_code' => strtoupper((string) ($type['code'] ?? 'UNCLASSIFIED')),
                'customer_class_code' => strtoupper((string) ($class['code'] ?? 'UNCLASSIFIED')),
            ];
        }

        $promoCodes = collect($definition['groups'])
            ->flatMap(fn ($group) => $group['conditions'])
            ->where('field', 'PROMO_QUALIFIED')
            ->map(fn ($condition) => strtoupper(trim((string) ($condition['value'] ?? ''))))
            ->filter()
            ->unique();

        $promoCandidates = [];
        foreach ($promoCodes as $code) {
            $promoCandidates[$code] = $this->discountRules->activeCandidatesForCustomerFilter(
                $businessId,
                $code,
                CarbonImmutable::now(config('yodamen.business_timezone', 'Asia/Manila')),
            );
        }

        return compact('serviceEvents', 'packageEvents', 'metrics', 'promoCandidates');
    }

    private function matchesCondition(Customer $customer, array $condition, array $context): bool
    {
        $field = strtoupper((string) $condition['field']);
        $operator = strtoupper((string) $condition['operator']);
        $customerId = (int) $customer->id;
        $metrics = $context['metrics'][$customerId] ?? [
            'last_visit' => null,
            'days_since_last_visit' => null,
            'lifetime_value' => 0,
            'total_completed_visits' => 0,
            'customer_type_code' => 'UNCLASSIFIED',
            'customer_class_code' => 'UNCLASSIFIED',
        ];

        return match ($field) {
            'SERVICE_AVAILED' => $this->matchesEventCondition(
                $context['serviceEvents']->get($customerId, collect()),
                $condition,
                'service_id',
            ),
            'SERVICE_CATEGORY_AVAILED' => $this->matchesEventCondition(
                $context['serviceEvents']->get($customerId, collect()),
                $condition,
                'category_id',
            ),
            'PACKAGE_PURCHASED' => $this->matchesEventCondition(
                $context['packageEvents']->get($customerId, collect()),
                $condition,
                'service_id',
            ),
            'VISIT_COUNT' => $this->compareNumber((float) $metrics['total_completed_visits'], $operator, $condition),
            'LIFETIME_VALUE' => $this->compareNumber((float) $metrics['lifetime_value'], $operator, $condition),
            'LAST_VISIT' => $this->matchesLastVisit($metrics['days_since_last_visit'], $operator, $condition),
            'PROMO_QUALIFIED' => $this->matchesPromo($customer, $metrics, $condition, $context['promoCandidates']),
            'LEAD_SOURCE' => $this->matchesInList((string) ($customer->leadSource?->code ?? ''), $operator, $condition['values'] ?? []),
            'BIRTH_MONTH' => $this->matchesInList((string) ($customer->birthday?->month ?? ''), $operator, $condition['values'] ?? []),
            'CUSTOMER_STATUS' => $this->matchesInList(strtoupper((string) $customer->status), $operator, $condition['values'] ?? []),
            'CUSTOMER_TYPE' => $this->matchesInList((string) $metrics['customer_type_code'], $operator, $condition['values'] ?? []),
            'CUSTOMER_CLASS' => $this->matchesInList((string) $metrics['customer_class_code'], $operator, $condition['values'] ?? []),
            default => false,
        };
    }

    private function matchesEventCondition(Collection $events, array $condition, string $valueKey): bool
    {
        $events = $this->applyPeriod($events, $condition);
        $operator = strtoupper((string) $condition['operator']);
        $targets = collect($condition['values'] ?? [])->map(fn ($value) => (int) $value)->filter()->unique()->values();
        $matching = $targets->isEmpty()
            ? $events
            : $events->filter(fn ($event) => $targets->contains((int) ($event[$valueKey] ?? 0)));

        return match ($operator) {
            'ANY_OF' => $targets->isNotEmpty() && $matching->isNotEmpty(),
            'ALL_OF' => $targets->isNotEmpty() && $targets->every(fn ($target) => $events->contains(fn ($event) => (int) ($event[$valueKey] ?? 0) === (int) $target)),
            'NONE_OF' => $targets->isNotEmpty() && $matching->isEmpty(),
            'NEVER' => $events->isEmpty(),
            'COUNT_AT_LEAST' => $matching->count() >= (int) ($condition['value'] ?? 0),
            'COUNT_AT_MOST' => $matching->count() <= (int) ($condition['value'] ?? 0),
            'LAST_MORE_THAN_DAYS' => $this->lastEventDays($matching) !== null && $this->lastEventDays($matching) > (int) ($condition['value'] ?? 0),
            'LAST_WITHIN_DAYS' => $this->lastEventDays($matching) !== null && $this->lastEventDays($matching) <= (int) ($condition['value'] ?? 0),
            default => false,
        };
    }

    private function applyPeriod(Collection $events, array $condition): Collection
    {
        $periodType = strtoupper((string) ($condition['period_type'] ?? 'ALL_TIME'));
        if ($periodType === 'ALL_TIME') {
            return $events;
        }

        $today = CarbonImmutable::now(config('yodamen.business_timezone', 'Asia/Manila'))->startOfDay();
        if ($periodType === 'LAST_N_DAYS') {
            $days = max(1, (int) ($condition['period_days'] ?? 1));
            $from = $today->subDays($days - 1);
            return $events->filter(function ($event) use ($from, $today) {
                if (empty($event['date'])) {
                    return false;
                }
                $date = CarbonImmutable::parse($event['date'])->startOfDay();
                return ! $date->lessThan($from) && ! $date->greaterThan($today);
            });
        }

        if ($periodType === 'BETWEEN_DATES') {
            $from = ! empty($condition['date_from']) ? CarbonImmutable::parse($condition['date_from'])->startOfDay() : null;
            $to = ! empty($condition['date_to']) ? CarbonImmutable::parse($condition['date_to'])->endOfDay() : null;
            return $events->filter(function ($event) use ($from, $to) {
                if (empty($event['date'])) return false;
                $date = CarbonImmutable::parse($event['date']);
                if ($from && $date->lessThan($from)) return false;
                if ($to && $date->greaterThan($to)) return false;
                return true;
            });
        }

        return $events;
    }

    private function compareNumber(float $actual, string $operator, array $condition): bool
    {
        $value = (float) ($condition['value'] ?? 0);
        $value2 = (float) ($condition['value2'] ?? 0);

        return match ($operator) {
            'AT_LEAST' => $actual >= $value,
            'AT_MOST' => $actual <= $value,
            'EQUALS' => abs($actual - $value) < 0.0001,
            'BETWEEN' => $actual >= min($value, $value2) && $actual <= max($value, $value2),
            default => false,
        };
    }

    private function matchesLastVisit(?int $days, string $operator, array $condition): bool
    {
        if ($operator === 'NEVER_VISITED') return $days === null;
        if ($days === null) return false;
        $value = (int) ($condition['value'] ?? 0);
        $value2 = (int) ($condition['value2'] ?? 0);

        return match ($operator) {
            'MORE_THAN_DAYS' => $days > $value,
            'WITHIN_DAYS' => $days <= $value,
            'BETWEEN_DAYS' => $days >= min($value, $value2) && $days <= max($value, $value2),
            default => false,
        };
    }

    private function matchesPromo(Customer $customer, array $metrics, array $condition, array $promoCandidates): bool
    {
        $code = strtoupper(trim((string) ($condition['value'] ?? '')));
        if ($code === '' || ! isset($promoCandidates[$code])) return false;
        return $this->discountRules->customerMatchesCandidates($customer, $promoCandidates[$code], $metrics);
    }

    private function matchesInList(string $actual, string $operator, array $values): bool
    {
        $targets = collect($values)->map(fn ($value) => strtoupper(trim((string) $value)))->filter();
        $contains = $targets->contains(strtoupper(trim($actual)));
        return $operator === 'NOT_IN' ? ! $contains : $contains;
    }

    private function lastEventDays(Collection $events): ?int
    {
        $date = $events->pluck('date')->filter()->sortDesc()->first();
        if (! $date) return null;
        return (int) CarbonImmutable::parse($date)->startOfDay()->diffInDays(
            CarbonImmutable::now(config('yodamen.business_timezone', 'Asia/Manila'))->startOfDay()
        );
    }

    private function referenceOptions(int $businessId, string $groupCode): Collection
    {
        $group = ReferenceGroup::query()
            ->where('business_id', $businessId)
            ->where('code', $groupCode)
            ->with(['values' => fn ($query) => $query->where('active', true)->orderBy('sort_order')->orderBy('display_value')])
            ->first();

        return $group?->values->map(fn ($value) => [
            'id' => $value->id,
            'code' => $value->code,
            'label' => $value->display_value,
        ])->values() ?? collect();
    }
}
