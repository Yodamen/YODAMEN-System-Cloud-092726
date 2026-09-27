<?php

namespace App\Services\Discounts;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\DiscountRule;
use App\Models\ReferenceGroup;
use App\Models\ReferenceValue;
use App\Models\Personnel;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Service;
use App\Models\User;
use App\Services\Customers\CustomerClassificationService;
use App\Services\Foundation\CodeSequenceService;
use App\Services\Security\AuditService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DiscountRuleService
{
    public function __construct(
        private readonly CodeSequenceService $codes,
        private readonly AuditService $audit,
        private readonly CustomerClassificationService $classification,
    ) {
    }

    public function list(int $businessId): Collection
    {
        return DiscountRule::query()
            ->where('business_id', $businessId)
            ->with(['branch', 'customer', 'service.category', 'serviceCategory'])
            ->orderBy('discount_code')
            ->orderByDesc('effective_from')
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->get()
            ->map(fn (DiscountRule $rule) => $this->serialize($rule));
    }

    public function formOptions(int $businessId): array
    {
        $services = Service::query()
            ->where('business_id', $businessId)
            ->with(['statusReference', 'category'])
            ->orderBy('name')
            ->get()
            ->filter(fn (Service $service) => strtoupper((string) $service->statusReference?->code) === 'ACTIVE')
            ->map(fn (Service $service) => [
                'id' => $service->id,
                'code' => $service->code,
                'name' => $service->name,
                'category_id' => $service->service_category_reference_id,
                'category_name' => $service->category?->display_value,
                'number_of_sessions' => (int) $service->number_of_sessions,
            ])->values();

        $customers = Customer::query()
            ->where('business_id', $businessId)
            ->where('status', 'ACTIVE')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'code', 'first_name', 'last_name', 'mobile_number'])
            ->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'code' => $customer->code,
                'name' => $customer->full_name,
                'mobile_number' => $customer->mobile_number,
            ])->values();

        return [
            'services' => $services,
            'customers' => $customers,
            'personnel' => Personnel::query()->where('business_id', $businessId)->where('status', 'ACTIVE')->orderBy('last_name')->orderBy('first_name')->get()->map(fn (Personnel $row) => ['id' => $row->id, 'code' => $row->code, 'name' => $row->full_name])->values(),
            'branches' => Branch::query()
                ->where('business_id', $businessId)
                ->where('status', 'ACTIVE')
                ->orderBy('name')
                ->get(['id', 'code', 'name'])
                ->map(fn (Branch $branch) => ['id' => $branch->id, 'code' => $branch->code, 'name' => $branch->name])
                ->values(),
            'service_categories' => $this->referenceValues($businessId, 'SERVICE_CATEGORY'),
            'customer_types' => collect($this->classification->typeOptions($businessId))
                ->map(fn (array $row) => ['id' => $row['id'], 'code' => $row['code'], 'name' => $row['name']])
                ->values(),
        ];
    }

    public function create(User $actor, array $data): DiscountRule
    {
        return DB::transaction(function () use ($actor, $data) {
            $normalized = $this->normalizeAndValidate((int) $actor->business_id, $data);
            $now = now();

            $rule = DiscountRule::query()->create([
                ...$normalized,
                'business_id' => $actor->business_id,
                'code' => $this->codes->next(
                    'DISCOUNT_RULE:'.$now->format('my'),
                    'DSR-'.$now->format('my').'-',
                    6,
                    $actor->business_id,
                ),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $rule->load(['branch', 'customer', 'service.category', 'serviceCategory']);

            $this->audit->record(
                action: 'CREATE_DISCOUNT_RULE',
                module: 'discount-rules',
                entityType: DiscountRule::class,
                entityId: $rule->id,
                after: $this->snapshot($rule),
                actor: $actor,
            );

            return $rule;
        });
    }

    public function updateById(User $actor, int $ruleId, array $data): DiscountRule
    {
        return DB::transaction(function () use ($actor, $ruleId, $data) {
            $rule = $this->findOwnedRuleForUpdate($actor, $ruleId);
            $before = $this->snapshot($rule);
            $normalized = $this->normalizeAndValidate((int) $actor->business_id, $data);

            $rule->update([
                ...$normalized,
                'updated_by' => $actor->id,
            ]);

            $rule->refresh()->load(['branch', 'customer', 'service.category', 'serviceCategory']);

            $this->audit->record(
                action: 'EDIT_DISCOUNT_RULE',
                module: 'discount-rules',
                entityType: DiscountRule::class,
                entityId: $rule->id,
                before: $before,
                after: $this->snapshot($rule),
                actor: $actor,
            );

            return $rule;
        });
    }

    public function toggleById(User $actor, int $ruleId): DiscountRule
    {
        return DB::transaction(function () use ($actor, $ruleId) {
            $rule = $this->findOwnedRuleForUpdate($actor, $ruleId);
            $before = $this->snapshot($rule);
            $nextStatus = strtoupper((string) $rule->status) === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE';

            $rule->update([
                'status' => $nextStatus,
                'updated_by' => $actor->id,
            ]);

            $rule->refresh()->load(['branch', 'customer', 'service.category', 'serviceCategory']);

            $this->audit->record(
                action: $nextStatus === 'ACTIVE' ? 'ACTIVATE_DISCOUNT_RULE' : 'DEACTIVATE_DISCOUNT_RULE',
                module: 'discount-rules',
                entityType: DiscountRule::class,
                entityId: $rule->id,
                before: $before,
                after: $this->snapshot($rule),
                actor: $actor,
            );

            return $rule;
        });
    }

    public function activeCandidatesForCode(int $businessId, string $discountCode, CarbonImmutable $asOf, ?int $branchId = null): Collection
    {
        $code = $this->normalizeCode($discountCode);
        $date = $asOf->toDateString();

        return DiscountRule::query()
            ->where('business_id', $businessId)
            ->where('discount_code', $code)
            ->where('status', 'ACTIVE')
            ->whereDate('effective_from', '<=', $date)
            ->where(function ($query) use ($date) {
                $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date);
            })
            ->where(function ($query) use ($branchId) {
                $query->whereNull('branch_id');
                if ($branchId) {
                    $query->orWhere('branch_id', $branchId);
                }
            })
            ->with(['branch', 'customer', 'service.category', 'serviceCategory'])
            ->get();
    }

    public function activeCandidatesForCustomerFilter(
        int $businessId,
        string $discountCode,
        CarbonImmutable $asOf,
    ): Collection {
        $code = $this->normalizeCode($discountCode);
        $date = $asOf->toDateString();

        if ($code === '') {
            return collect();
        }

        return DiscountRule::query()
            ->where('business_id', $businessId)
            ->where('discount_code', $code)
            ->where('status', 'ACTIVE')
            ->whereDate('effective_from', '<=', $date)
            ->where(function ($query) use ($date) {
                $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date);
            })
            ->with(['branch', 'customer', 'service.category', 'serviceCategory'])
            ->get();
    }

    public function customerMatchesCandidates(
        Customer $customer,
        Collection $candidates,
        array $customerMetrics = [],
    ): bool {
        $derivedCustomerType = $this->classification->typeFor(
            (int) $customer->business_id,
            (int) ($customerMetrics['total_completed_visits'] ?? 0),
        )['code'];

        return $candidates->contains(fn (DiscountRule $rule) => $this->matchesCustomerEligibility(
            $rule,
            (int) $customer->id,
            $derivedCustomerType,
            $customerMetrics,
        ));
    }

    public function resolveFromCandidates(
        Sale $sale,
        SaleItem $item,
        Collection $candidates,
        array $customerMetrics = [],
    ): ?DiscountRule {
        $item->loadMissing('service');
        $customerType = strtoupper((string) ($sale->booking?->customer_type ?? ''));
        $serviceCategoryId = $item->service?->service_category_reference_id;
        $itemType = strtoupper((string) $item->item_type);

        return $candidates
            ->filter(fn (DiscountRule $rule) => $this->matches(
                $rule,
                $sale,
                $item,
                $customerType,
                $serviceCategoryId,
                $itemType,
                $customerMetrics,
            ))
            ->sort(function (DiscountRule $a, DiscountRule $b) use ($item, $serviceCategoryId): int {
                $specificity = $this->specificityScore($b, (int) ($item->service_id ?? 0), $serviceCategoryId) <=> $this->specificityScore($a, (int) ($item->service_id ?? 0), $serviceCategoryId);
                if ($specificity !== 0) return $specificity;

                $priority = ((int) $b->priority) <=> ((int) $a->priority);
                if ($priority !== 0) return $priority;

                return ((int) $a->id) <=> ((int) $b->id);
            })
            ->first();
    }

    public function serialize(DiscountRule $rule): array
    {
        $rule->loadMissing(['branch', 'customer', 'service.category', 'serviceCategory']);
        $today = now()->toDateString();
        $displayStatus = strtoupper((string) $rule->status);

        if ($displayStatus === 'ACTIVE') {
            if ($rule->effective_from?->toDateString() > $today) {
                $displayStatus = 'SCHEDULED';
            } elseif ($rule->effective_until && $rule->effective_until->toDateString() < $today) {
                $displayStatus = 'EXPIRED';
            }
        }

        $personnelIds = $this->ruleIds($rule->personnel_ids);
        $serviceIds = $this->ruleIds($rule->service_ids, $rule->service_id);
        $categoryIds = $this->ruleIds($rule->service_category_reference_ids, $rule->service_category_reference_id);
        $personnelList = Personnel::query()->where('business_id', $rule->business_id)->whereIn('id', $personnelIds)->orderBy('last_name')->orderBy('first_name')->get()->map(fn (Personnel $row) => ['id'=>$row->id,'code'=>$row->code,'name'=>$row->full_name])->values();
        $serviceList = Service::query()->where('business_id', $rule->business_id)->whereIn('id', $serviceIds)->orderBy('name')->get()->map(fn (Service $row) => ['id'=>$row->id,'code'=>$row->code,'name'=>$row->name])->values();
        $categoryList = ReferenceValue::query()->whereIn('id', $categoryIds)->orderBy('display_value')->get()->map(fn (ReferenceValue $row) => ['id'=>$row->id,'code'=>$row->code,'name'=>$row->display_value])->values();

        return [
            'id' => $rule->id,
            'code' => $rule->code,
            'rule_name' => $rule->rule_name,
            'discount_code' => $rule->discount_code,
            'branch' => $rule->branch ? ['id' => $rule->branch->id, 'code' => $rule->branch->code, 'name' => $rule->branch->name] : null,
            'personnel_ids' => $personnelIds,
            'personnel_list' => $personnelList,
            'applies_to' => $rule->applies_to,
            'customer' => $rule->customer ? ['id' => $rule->customer->id, 'code' => $rule->customer->code, 'name' => $rule->customer->full_name] : null,
            'customer_type' => $rule->customer_type,
            'min_lifetime_value' => $rule->min_lifetime_value !== null ? (float) $rule->min_lifetime_value : null,
            'max_lifetime_value' => $rule->max_lifetime_value !== null ? (float) $rule->max_lifetime_value : null,
            'last_visit_operator' => $rule->last_visit_operator,
            'last_visit_days_min' => $rule->last_visit_days_min !== null ? (int) $rule->last_visit_days_min : null,
            'last_visit_days_max' => $rule->last_visit_days_max !== null ? (int) $rule->last_visit_days_max : null,
            'item_scope' => $rule->item_scope,
            'service' => $rule->service ? [
                'id' => $rule->service->id,
                'code' => $rule->service->code,
                'name' => $rule->service->name,
            ] : null,
            'service_ids' => $serviceIds,
            'service_list' => $serviceList,
            'service_category' => $rule->serviceCategory ? [
                'id' => $rule->serviceCategory->id,
                'code' => $rule->serviceCategory->code,
                'name' => $rule->serviceCategory->display_value,
            ] : null,
            'service_category_reference_ids' => $categoryIds,
            'service_category_list' => $categoryList,
            'calculation_type' => $rule->calculation_type,
            'rate_value' => (float) $rule->rate_value,
            'effective_from' => $rule->effective_from?->toDateString(),
            'effective_until' => $rule->effective_until?->toDateString(),
            'priority' => (int) $rule->priority,
            'stackable' => (bool) ($rule->stackable ?? true),
            'status' => $rule->status,
            'display_status' => $displayStatus,
            'specificity' => $this->specificityLabel($rule),
            'notes' => $rule->notes,
        ];
    }

    public function normalizeCode(string $discountCode): string
    {
        return strtoupper(trim($discountCode));
    }

    private function findOwnedRuleForUpdate(User $actor, int $ruleId): DiscountRule
    {
        if ($ruleId <= 0) abort(404);

        return DiscountRule::query()
            ->where('business_id', (int) $actor->business_id)
            ->whereKey($ruleId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function normalizeAndValidate(int $businessId, array $data): array
    {
        $appliesTo = strtoupper((string) $data['applies_to']);
        $itemScope = strtoupper((string) $data['item_scope']);
        $calculationType = strtoupper((string) $data['calculation_type']);
        $customerId = $appliesTo === 'CUSTOMER' ? ($data['customer_id'] ?? null) : null;
        $customerType = $appliesTo === 'CUSTOMER_TYPE' ? strtoupper(trim((string) ($data['customer_type'] ?? ''))) : null;
        $minLifetimeValue = filled($data['min_lifetime_value'] ?? null)
            ? round((float) $data['min_lifetime_value'], 2)
            : null;
        $maxLifetimeValue = filled($data['max_lifetime_value'] ?? null)
            ? round((float) $data['max_lifetime_value'], 2)
            : null;
        $lastVisitOperator = filled($data['last_visit_operator'] ?? null)
            ? strtoupper(trim((string) $data['last_visit_operator']))
            : null;
        $lastVisitDaysMin = filled($data['last_visit_days_min'] ?? null)
            ? (int) $data['last_visit_days_min']
            : null;
        $lastVisitDaysMax = filled($data['last_visit_days_max'] ?? null)
            ? (int) $data['last_visit_days_max']
            : null;
        $personnelIds = $this->inputIds($data, 'personnel_ids', null);
        $serviceIds = $this->inputIds($data, 'service_ids', 'service_id');
        $categoryIds = $this->inputIds($data, 'service_category_reference_ids', 'service_category_reference_id');
        $branchId = $data['branch_id'] ?? null;
        $rateValue = round((float) $data['rate_value'], 4);
        $discountCode = $this->normalizeCode((string) $data['discount_code']);

        if (! in_array($appliesTo, ['ALL', 'CUSTOMER_TYPE', 'CUSTOMER'], true)) {
            throw new DomainException('Invalid customer scope for this discount rule.');
        }
        if (! in_array($itemScope, ['ALL', 'SERVICE', 'PACKAGE', 'PRODUCT'], true)) {
            throw new DomainException('Invalid item scope for this discount rule.');
        }
        if (! in_array($calculationType, ['PERCENTAGE', 'FIXED'], true)) {
            throw new DomainException('Invalid discount method.');
        }
        if ($discountCode === '') {
            throw new DomainException('Discount Code is required.');
        }
        if ($rateValue <= 0) {
            throw new DomainException('Discount Rate must be greater than zero.');
        }

        if ($calculationType === 'PERCENTAGE' && $rateValue > 100) {
            throw new DomainException('Percentage discount cannot exceed 100%.');
        }

        if ($minLifetimeValue !== null && $minLifetimeValue < 0) {
            throw new DomainException('Minimum Lifetime Value cannot be negative.');
        }
        if ($maxLifetimeValue !== null && $maxLifetimeValue < 0) {
            throw new DomainException('Maximum Lifetime Value cannot be negative.');
        }
        if ($minLifetimeValue !== null && $maxLifetimeValue !== null && $maxLifetimeValue < $minLifetimeValue) {
            throw new DomainException('Maximum Lifetime Value cannot be lower than Minimum Lifetime Value.');
        }

        if ($lastVisitOperator !== null && ! in_array($lastVisitOperator, ['MORE_THAN', 'WITHIN', 'BETWEEN', 'NEVER'], true)) {
            throw new DomainException('Invalid Last Visit condition.');
        }
        if ($lastVisitDaysMin !== null && $lastVisitDaysMin < 0) {
            throw new DomainException('Last Visit days cannot be negative.');
        }
        if ($lastVisitDaysMax !== null && $lastVisitDaysMax < 0) {
            throw new DomainException('Last Visit days cannot be negative.');
        }

        if ($lastVisitOperator === 'MORE_THAN') {
            if ($lastVisitDaysMin === null) {
                throw new DomainException('Enter the minimum number of days for the Last Visit condition.');
            }
            $lastVisitDaysMax = null;
        } elseif ($lastVisitOperator === 'WITHIN') {
            if ($lastVisitDaysMax === null) {
                throw new DomainException('Enter the maximum number of days for the Last Visit condition.');
            }
            $lastVisitDaysMin = null;
        } elseif ($lastVisitOperator === 'BETWEEN') {
            if ($lastVisitDaysMin === null || $lastVisitDaysMax === null) {
                throw new DomainException('Enter both day values for the Last Visit condition.');
            }
            if ($lastVisitDaysMax < $lastVisitDaysMin) {
                throw new DomainException('Last Visit maximum days cannot be lower than minimum days.');
            }
        } elseif ($lastVisitOperator === 'NEVER') {
            $lastVisitDaysMin = null;
            $lastVisitDaysMax = null;
        } else {
            $lastVisitDaysMin = null;
            $lastVisitDaysMax = null;
        }

        if ($itemScope === 'PRODUCT' && (! empty($serviceIds) || ! empty($categoryIds))) {
            throw new DomainException('Retail discount rules cannot be restricted by Service or Service Category.');
        }

        if ($customerId) {
            Customer::query()->where('business_id', $businessId)->whereKey($customerId)->firstOrFail();
        }

        if ($customerType !== null && ! collect($this->classification->typeOptions($businessId))->contains(
            fn (array $row) => strtoupper((string) $row['code']) === $customerType
        )) {
            throw new DomainException('Selected Customer Type is invalid for this discount rule.');
        }

        if ($branchId) {
            Branch::query()->where('business_id', $businessId)->whereKey($branchId)->firstOrFail();
        }

        foreach ($personnelIds as $personnelId) Personnel::query()->where('business_id', $businessId)->where('status','ACTIVE')->whereKey($personnelId)->firstOrFail();
        foreach ($serviceIds as $serviceId) Service::query()->where('business_id', $businessId)->whereKey($serviceId)->firstOrFail();
        foreach ($categoryIds as $categoryId) $this->assertReferenceValue($businessId, 'SERVICE_CATEGORY', (int) $categoryId);

        $effectiveFrom = CarbonImmutable::parse($data['effective_from'])->startOfDay();
        $effectiveUntil = filled($data['effective_until'] ?? null)
            ? CarbonImmutable::parse($data['effective_until'])->startOfDay()
            : null;

        return [
            'rule_name' => trim((string) $data['rule_name']),
            'discount_code' => $discountCode,
            'branch_id' => $branchId,
            'personnel_ids' => $personnelIds ?: null,
            'applies_to' => $appliesTo,
            'customer_id' => $customerId,
            'customer_type' => $customerType,
            'min_lifetime_value' => $minLifetimeValue,
            'max_lifetime_value' => $maxLifetimeValue,
            'last_visit_operator' => $lastVisitOperator,
            'last_visit_days_min' => $lastVisitDaysMin,
            'last_visit_days_max' => $lastVisitDaysMax,
            'item_scope' => $itemScope,
            'service_id' => $serviceIds[0] ?? null,
            'service_ids' => $serviceIds ?: null,
            'service_category_reference_id' => $categoryIds[0] ?? null,
            'service_category_reference_ids' => $categoryIds ?: null,
            'calculation_type' => $calculationType,
            'rate_value' => $rateValue,
            'effective_from' => $effectiveFrom->toDateString(),
            'effective_until' => $effectiveUntil?->toDateString(),
            'priority' => (int) ($data['priority'] ?? 0),
            'stackable' => (bool) ($data['stackable'] ?? true),
            'status' => strtoupper((string) ($data['status'] ?? 'ACTIVE')) === 'INACTIVE' ? 'INACTIVE' : 'ACTIVE',
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
        ];
    }

    private function matches(
        DiscountRule $rule,
        Sale $sale,
        SaleItem $item,
        string $customerType,
        ?int $serviceCategoryId,
        string $itemType,
        array $customerMetrics,
    ): bool {
        if (! $this->matchesCustomerEligibility(
            $rule,
            (int) $sale->customer_id,
            $customerType,
            $customerMetrics,
        )) return false;

        $itemScopeMatches = match (strtoupper((string) $rule->item_scope)) {
            'SERVICE' => $itemType === 'SERVICE',
            'PACKAGE' => $itemType === 'PACKAGE',
            'PRODUCT' => $itemType === 'PRODUCT',
            default => in_array($itemType, ['SERVICE', 'PACKAGE', 'PRODUCT'], true),
        };
        if (! $itemScopeMatches) return false;

        $personnelIds = $this->ruleIds($rule->personnel_ids);
        if (! empty($personnelIds) && ! in_array((int) $item->assigned_personnel_id, $personnelIds, true)) return false;

        $serviceIds = $this->ruleIds($rule->service_ids, $rule->service_id);
        $categoryIds = $this->ruleIds($rule->service_category_reference_ids, $rule->service_category_reference_id);
        if (empty($serviceIds) && empty($categoryIds)) return true;
        return ($item->service_id && in_array((int) $item->service_id, $serviceIds, true))
            || ($serviceCategoryId && in_array((int) $serviceCategoryId, $categoryIds, true));
    }

    private function matchesCustomerEligibility(
        DiscountRule $rule,
        int $customerId,
        string $customerType,
        array $customerMetrics,
    ): bool {
        $customerMatches = match ($rule->applies_to) {
            'CUSTOMER' => (int) $rule->customer_id === $customerId,
            'CUSTOMER_TYPE' => strtoupper((string) $rule->customer_type) === strtoupper($customerType),
            default => true,
        };

        return $customerMatches && $this->matchesCustomerMetrics($rule, $customerMetrics);
    }

    private function matchesCustomerMetrics(DiscountRule $rule, array $metrics): bool
    {
        $lifetimeValue = round((float) ($metrics['lifetime_value'] ?? 0), 2);

        if ($rule->min_lifetime_value !== null && $lifetimeValue < (float) $rule->min_lifetime_value) {
            return false;
        }

        if ($rule->max_lifetime_value !== null && $lifetimeValue > (float) $rule->max_lifetime_value) {
            return false;
        }

        $operator = strtoupper((string) ($rule->last_visit_operator ?? ''));
        if ($operator === '') {
            return true;
        }

        $days = array_key_exists('days_since_last_visit', $metrics)
            && $metrics['days_since_last_visit'] !== null
            ? (int) $metrics['days_since_last_visit']
            : null;

        return match ($operator) {
            'MORE_THAN' => $days !== null && $days > (int) $rule->last_visit_days_min,
            'WITHIN' => $days !== null && $days <= (int) $rule->last_visit_days_max,
            'BETWEEN' => $days !== null
                && $days >= (int) $rule->last_visit_days_min
                && $days <= (int) $rule->last_visit_days_max,
            'NEVER' => $days === null,
            default => true,
        };
    }

    private function specificityScore(DiscountRule $rule, int $serviceId = 0, ?int $serviceCategoryId = null): int
    {
        $customer = match ($rule->applies_to) {
            'CUSTOMER' => 300,
            'CUSTOMER_TYPE' => 200,
            default => 100,
        };
        $serviceIds = $this->ruleIds($rule->service_ids, $rule->service_id);
        $categoryIds = $this->ruleIds($rule->service_category_reference_ids, $rule->service_category_reference_id);
        $service = $serviceId && in_array($serviceId, $serviceIds, true) ? 30 : (($serviceCategoryId && in_array((int)$serviceCategoryId, $categoryIds, true)) ? 20 : 10);
        $personnel = ! empty($this->ruleIds($rule->personnel_ids)) ? 15 : 0;
        $itemScope = strtoupper((string) $rule->item_scope) === 'ALL' ? 0 : 5;
        $branch = $rule->branch_id ? 2 : 0;

        return $customer + $service + $personnel + $itemScope + $branch;
    }

    private function specificityLabel(DiscountRule $rule): string
    {
        $customer = match ($rule->applies_to) {
            'CUSTOMER' => 'Customer',
            'CUSTOMER_TYPE' => 'Customer Type',
            default => 'All Customers',
        };
        $serviceIds = $this->ruleIds($rule->service_ids, $rule->service_id);
        $categoryIds = $this->ruleIds($rule->service_category_reference_ids, $rule->service_category_reference_id);
        $service = ! empty($serviceIds) && ! empty($categoryIds) ? 'Selected Services + Categories' : (! empty($serviceIds) ? (count($serviceIds)>1?'Selected Services':'Specific Service') : (! empty($categoryIds) ? (count($categoryIds)>1?'Service Categories':'Service Category') : 'All Services'));
        $personnel = ! empty($this->ruleIds($rule->personnel_ids)) ? ' + Selected Personnel' : '';
        $item = match (strtoupper((string) $rule->item_scope)) {
            'SERVICE' => 'Service',
            'PACKAGE' => 'Package',
            'PRODUCT' => 'Retail',
            default => 'All Chargeable',
        };
        $branch = $rule->branch_id ? ' + Branch' : '';

        return $customer.$personnel.' + '.$service.' + '.$item.$branch;
    }

    private function inputIds(array $data, string $pluralKey, ?string $singularKey): array
    {
        $values = $data[$pluralKey] ?? [];
        if (! is_array($values)) $values = [];
        if (empty($values) && $singularKey && ! empty($data[$singularKey])) $values = [$data[$singularKey]];
        return collect($values)->map(fn($v)=>(int)$v)->filter()->unique()->values()->all();
    }

    private function ruleIds(?array $values, mixed $legacyValue = null): array
    {
        $ids = collect($values ?: [])->map(fn($v)=>(int)$v)->filter()->unique()->values()->all();
        if (empty($ids) && $legacyValue) $ids=[(int)$legacyValue];
        return $ids;
    }

    private function referenceValues(int $businessId, string $groupCode): Collection
    {
        $group = ReferenceGroup::query()
            ->where('business_id', $businessId)
            ->where('code', $groupCode)
            ->first();

        if (! $group) return collect();

        return ReferenceValue::query()
            ->where('reference_group_id', $group->id)
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('display_value')
            ->get()
            ->map(fn (ReferenceValue $value) => [
                'id' => $value->id,
                'code' => $value->code,
                'name' => $value->display_value,
            ])->values();
    }

    private function assertReferenceValue(int $businessId, string $groupCode, int $valueId): void
    {
        $exists = ReferenceValue::query()
            ->whereKey($valueId)
            ->where('active', true)
            ->whereHas('group', fn ($query) => $query->where('business_id', $businessId)->where('code', $groupCode))
            ->exists();

        if (! $exists) {
            throw new DomainException('Selected reference value is invalid for this discount rule.');
        }
    }

    private function referenceValueCodeExists(int $businessId, string $groupCode, string $code): bool
    {
        return ReferenceValue::query()
            ->where('code', $code)
            ->where('active', true)
            ->whereHas('group', fn ($query) => $query->where('business_id', $businessId)->where('code', $groupCode))
            ->exists();
    }

    private function snapshot(DiscountRule $rule): array
    {
        return [
            'id' => $rule->id,
            'code' => $rule->code,
            'rule_name' => $rule->rule_name,
            'discount_code' => $rule->discount_code,
            'branch_id' => $rule->branch_id,
            'personnel_ids' => $this->ruleIds($rule->personnel_ids),
            'applies_to' => $rule->applies_to,
            'customer_id' => $rule->customer_id,
            'customer_type' => $rule->customer_type,
            'min_lifetime_value' => $rule->min_lifetime_value !== null ? (float) $rule->min_lifetime_value : null,
            'max_lifetime_value' => $rule->max_lifetime_value !== null ? (float) $rule->max_lifetime_value : null,
            'last_visit_operator' => $rule->last_visit_operator,
            'last_visit_days_min' => $rule->last_visit_days_min !== null ? (int) $rule->last_visit_days_min : null,
            'last_visit_days_max' => $rule->last_visit_days_max !== null ? (int) $rule->last_visit_days_max : null,
            'item_scope' => $rule->item_scope,
            'service_id' => $rule->service_id,
            'service_ids' => $this->ruleIds($rule->service_ids, $rule->service_id),
            'service_category_reference_id' => $rule->service_category_reference_id,
            'service_category_reference_ids' => $this->ruleIds($rule->service_category_reference_ids, $rule->service_category_reference_id),
            'calculation_type' => $rule->calculation_type,
            'rate_value' => (float) $rule->rate_value,
            'effective_from' => $rule->effective_from?->toDateString(),
            'effective_until' => $rule->effective_until?->toDateString(),
            'priority' => (int) $rule->priority,
            'stackable' => (bool) ($rule->stackable ?? true),
            'status' => $rule->status,
            'notes' => $rule->notes,
        ];
    }
}
