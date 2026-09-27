<?php

namespace App\Services\Commissions;

use App\Models\Branch;
use App\Models\CommissionRule;
use App\Models\Personnel;
use App\Models\ReferenceGroup;
use App\Models\ReferenceValue;
use App\Models\RetailProduct;
use App\Models\Service;
use App\Models\User;
use App\Services\Foundation\CodeSequenceService;
use App\Services\Security\AuditService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CommissionRuleService
{
    public function __construct(
        private readonly CodeSequenceService $codes,
        private readonly AuditService $audit,
    ) {
    }

    public function list(int $businessId): Collection
    {
        return CommissionRule::query()
            ->where('business_id', $businessId)
            ->with(['branch', 'personnel', 'position', 'service.category', 'serviceCategory', 'retailProduct'])
            ->orderByDesc('effective_from')
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->get()
            ->map(fn (CommissionRule $rule) => $this->serialize($rule));
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

        $personnel = Personnel::query()
            ->where('business_id', $businessId)
            ->where('commission_eligible', true)
            ->where('status', 'ACTIVE')
            ->with(['position', 'branch'])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->map(fn (Personnel $personnel) => [
                'id' => $personnel->id,
                'code' => $personnel->code,
                'name' => $personnel->full_name,
                'position_id' => $personnel->position_reference_id,
                'position_name' => $personnel->position?->display_value,
                'branch_id' => $personnel->branch_id,
                'branch_name' => $personnel->branch?->name,
            ])->values();

        $retailProducts = RetailProduct::query()
            ->where('business_id', $businessId)
            ->where('status', 'ACTIVE')
            ->where('stock_type', 'INVENTORY')
            ->where('retail_available', true)
            ->orderBy('name')
            ->get()
            ->map(fn (RetailProduct $product) => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'category' => $product->category,
            ])->values();

        $retailCategories = $this->referenceValues($businessId, 'PRODUCT_CATEGORY')
            ->pluck('name')
            ->merge($retailProducts->pluck('category'))
            ->filter()
            ->unique(fn ($value) => strtolower(trim((string) $value)))
            ->sort()
            ->values();

        return [
            'services' => $services,
            'retail_products' => $retailProducts,
            'retail_categories' => $retailCategories,
            'personnel' => $personnel,
            'branches' => Branch::query()
                ->where('business_id', $businessId)
                ->where('status', 'ACTIVE')
                ->orderBy('name')
                ->get(['id', 'code', 'name'])
                ->map(fn (Branch $branch) => ['id' => $branch->id, 'code' => $branch->code, 'name' => $branch->name])
                ->values(),
            'positions' => $this->referenceValues($businessId, 'EMPLOYEE_POSITION'),
            'service_categories' => $this->referenceValues($businessId, 'SERVICE_CATEGORY'),
        ];
    }

    public function create(User $actor, array $data): CommissionRule
    {
        return DB::transaction(function () use ($actor, $data) {
            $normalized = $this->normalizeAndValidate($actor->business_id, $data);
            $now = now();

            $rule = CommissionRule::query()->create([
                ...$normalized,
                'business_id' => $actor->business_id,
                'code' => $this->codes->next(
                    'COMMISSION_RULE:'.$now->format('my'),
                    'CRL-'.$now->format('my').'-',
                    6,
                    $actor->business_id,
                ),
                'basis' => $normalized['commission_scope'] === 'RETAIL' ? 'NET_RETAIL_SALE' : 'STANDARD_SERVICE_PRICE',
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $rule->load(['branch', 'personnel', 'position', 'service.category', 'serviceCategory']);

            $this->audit->record(
                action: 'CREATE_COMMISSION_RULE',
                module: 'commission-rules',
                entityType: CommissionRule::class,
                entityId: $rule->id,
                after: $this->snapshot($rule),
                actor: $actor,
            );

            return $rule;
        });
    }

    public function updateById(User $actor, int $ruleId, array $data): CommissionRule
    {
        return DB::transaction(function () use ($actor, $ruleId, $data) {
            $rule = $this->findOwnedRuleForUpdate($actor, $ruleId);
            $before = $this->snapshot($rule);
            $normalized = $this->normalizeAndValidate((int) $actor->business_id, $data);

            $rule->update([
                ...$normalized,
                'basis' => $normalized['commission_scope'] === 'RETAIL' ? 'NET_RETAIL_SALE' : 'STANDARD_SERVICE_PRICE',
                'updated_by' => $actor->id,
            ]);

            $rule->refresh()->load(['branch', 'personnel', 'position', 'service.category', 'serviceCategory']);

            $this->audit->record(
                action: 'EDIT_COMMISSION_RULE',
                module: 'commission-rules',
                entityType: CommissionRule::class,
                entityId: $rule->id,
                before: $before,
                after: $this->snapshot($rule),
                actor: $actor,
            );

            return $rule;
        });
    }

    public function toggleById(User $actor, int $ruleId): CommissionRule
    {
        return DB::transaction(function () use ($actor, $ruleId) {
            $rule = $this->findOwnedRuleForUpdate($actor, $ruleId);
            $before = $this->snapshot($rule);
            $nextStatus = strtoupper((string) $rule->status) === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE';

            $rule->update([
                'status' => $nextStatus,
                'updated_by' => $actor->id,
            ]);

            $rule->refresh()->load(['branch', 'personnel', 'position', 'service.category', 'serviceCategory']);

            $this->audit->record(
                action: $nextStatus === 'ACTIVE' ? 'ACTIVATE_COMMISSION_RULE' : 'DEACTIVATE_COMMISSION_RULE',
                module: 'commission-rules',
                entityType: CommissionRule::class,
                entityId: $rule->id,
                before: $before,
                after: $this->snapshot($rule),
                actor: $actor,
            );

            return $rule;
        });
    }

    private function findOwnedRuleForUpdate(User $actor, int $ruleId): CommissionRule
    {
        if ($ruleId <= 0) {
            abort(404);
        }

        return CommissionRule::query()
            ->where('business_id', (int) $actor->business_id)
            ->whereKey($ruleId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Resolve the winning rule using the approved Apps Script hierarchy:
     * Employee > Position > All Staff; Specific Service > Category > All Services;
     * branch-specific scope adds specificity; Priority only resolves equal specificity.
     */
    public function resolveFor(
        int $businessId,
        Personnel $personnel,
        Service $service,
        CarbonImmutable $asOf,
        ?int $branchId = null,
    ): ?CommissionRule {
        if (! $personnel->commission_eligible) {
            return null;
        }

        $serviceCategoryId = $service->service_category_reference_id;
        $positionId = $personnel->position_reference_id;
        $date = $asOf->toDateString();

        $candidates = CommissionRule::query()
            ->where('business_id', $businessId)
            ->where('commission_scope', 'SERVICE')
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
            ->get()
            ->filter(fn (CommissionRule $rule) => $this->matches($rule, $personnel, $service, $positionId, $serviceCategoryId))
            ->sort(function (CommissionRule $a, CommissionRule $b) use ($service, $serviceCategoryId): int {
                $specificity = $this->specificityScore($b, (int) $service->id, $serviceCategoryId) <=> $this->specificityScore($a, (int) $service->id, $serviceCategoryId);
                if ($specificity !== 0) {
                    return $specificity;
                }

                $priority = ((int) $b->priority) <=> ((int) $a->priority);
                if ($priority !== 0) {
                    return $priority;
                }

                return ((int) $a->id) <=> ((int) $b->id);
            });

        return $candidates->first();
    }

    public function resolveRetailFor(
        int $businessId,
        Personnel $personnel,
        CarbonImmutable $asOf,
        ?int $branchId = null,
        ?RetailProduct $product = null,
    ): ?CommissionRule {
        if (! $personnel->commission_eligible) {
            return null;
        }

        $positionId = $personnel->position_reference_id;
        $date = $asOf->toDateString();

        $candidates = CommissionRule::query()
            ->where('business_id', $businessId)
            ->where('commission_scope', 'RETAIL')
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
            ->get()
            ->filter(function (CommissionRule $rule) use ($personnel, $positionId, $product) {
                $personnelIds = $this->ruleIds($rule->personnel_ids, $rule->personnel_id);
                $staffMatches = match ($rule->applies_to) {
                    'EMPLOYEE' => in_array((int) $personnel->id, $personnelIds, true),
                    'POSITION' => $positionId && (int) $rule->position_reference_id === (int) $positionId,
                    default => true,
                };
                if (! $staffMatches) return false;
                if ($rule->retail_product_id) {
                    return $product && (int) $rule->retail_product_id === (int) $product->id;
                }
                if ($rule->retail_category) {
                    return $product && trim((string) $product->category) !== ''
                        && strcasecmp((string) $rule->retail_category, (string) $product->category) === 0;
                }
                return true;
            })
            ->sort(function (CommissionRule $a, CommissionRule $b): int {
                $specificity = $this->retailSpecificityScore($b) <=> $this->retailSpecificityScore($a);
                if ($specificity !== 0) return $specificity;

                $priority = ((int) $b->priority) <=> ((int) $a->priority);
                if ($priority !== 0) return $priority;

                return ((int) $a->id) <=> ((int) $b->id);
            });

        return $candidates->first();
    }

    public function serialize(CommissionRule $rule): array
    {
        $rule->loadMissing(['branch', 'personnel', 'position', 'service.category', 'serviceCategory', 'retailProduct']);
        $today = now()->toDateString();
        $displayStatus = strtoupper((string) $rule->status);

        if ($displayStatus === 'ACTIVE') {
            if ($rule->effective_from?->toDateString() > $today) {
                $displayStatus = 'SCHEDULED';
            } elseif ($rule->effective_until && $rule->effective_until->toDateString() < $today) {
                $displayStatus = 'EXPIRED';
            }
        }

        $personnelIds = $this->ruleIds($rule->personnel_ids, $rule->personnel_id);
        $serviceIds = $this->ruleIds($rule->service_ids, $rule->service_id);
        $categoryIds = $this->ruleIds($rule->service_category_reference_ids, $rule->service_category_reference_id);

        $personnelList = Personnel::query()->where('business_id', $rule->business_id)->whereIn('id', $personnelIds)
            ->orderBy('last_name')->orderBy('first_name')->get()->map(fn (Personnel $row) => ['id' => $row->id, 'code' => $row->code, 'name' => $row->full_name])->values();
        $serviceList = Service::query()->where('business_id', $rule->business_id)->whereIn('id', $serviceIds)
            ->orderBy('name')->get()->map(fn (Service $row) => ['id' => $row->id, 'code' => $row->code, 'name' => $row->name, 'number_of_sessions' => (int) $row->number_of_sessions])->values();
        $categoryList = ReferenceValue::query()->whereIn('id', $categoryIds)->orderBy('display_value')->get()
            ->map(fn (ReferenceValue $row) => ['id' => $row->id, 'code' => $row->code, 'name' => $row->display_value])->values();

        return [
            'id' => $rule->id,
            'code' => $rule->code,
            'rule_name' => $rule->rule_name,
            'branch' => $rule->branch ? ['id' => $rule->branch->id, 'code' => $rule->branch->code, 'name' => $rule->branch->name] : null,
            'applies_to' => $rule->applies_to,
            'commission_scope' => $rule->commission_scope ?: 'SERVICE',
            'personnel' => $rule->personnel ? ['id' => $rule->personnel->id, 'code' => $rule->personnel->code, 'name' => $rule->personnel->full_name] : null,
            'personnel_list' => $personnelList,
            'personnel_ids' => $personnelIds,
            'position' => $rule->position ? ['id' => $rule->position->id, 'code' => $rule->position->code, 'name' => $rule->position->display_value] : null,
            'service' => $rule->service ? ['id' => $rule->service->id, 'code' => $rule->service->code, 'name' => $rule->service->name, 'number_of_sessions' => (int) $rule->service->number_of_sessions] : null,
            'service_list' => $serviceList,
            'service_ids' => $serviceIds,
            'service_category' => $rule->serviceCategory ? ['id' => $rule->serviceCategory->id, 'code' => $rule->serviceCategory->code, 'name' => $rule->serviceCategory->display_value] : null,
            'service_category_list' => $categoryList,
            'service_category_reference_ids' => $categoryIds,
            'retail_product' => $rule->retailProduct ? ['id' => $rule->retailProduct->id, 'sku' => $rule->retailProduct->sku, 'name' => $rule->retailProduct->name, 'category' => $rule->retailProduct->category] : null,
            'retail_category' => $rule->retail_category,
            'calculation_type' => $rule->calculation_type,
            'rate_value' => (float) $rule->rate_value,
            'rate_percentage' => $rule->calculation_type === 'PERCENTAGE' ? (float) $rule->rate_value : null,
            'basis' => $rule->basis,
            'effective_from' => $rule->effective_from?->toDateString(),
            'effective_until' => $rule->effective_until?->toDateString(),
            'priority' => (int) $rule->priority,
            'status' => $rule->status,
            'display_status' => $displayStatus,
            'specificity' => strtoupper((string) $rule->commission_scope) === 'RETAIL' ? $this->retailSpecificityLabel($rule) : $this->specificityLabel($rule),
            'notes' => $rule->notes,
        ];
    }

    private function normalizeAndValidate(int $businessId, array $data): array
    {
        $appliesTo = strtoupper((string) $data['applies_to']);
        $commissionScope = strtoupper((string) ($data['commission_scope'] ?? 'SERVICE'));
        $calculationType = strtoupper((string) $data['calculation_type']);
        $personnelIds = $appliesTo === 'EMPLOYEE' ? $this->inputIds($data, 'personnel_ids', 'personnel_id') : [];
        $positionId = $appliesTo === 'POSITION' ? ($data['position_reference_id'] ?? null) : null;
        $serviceIds = $commissionScope === 'SERVICE' ? $this->inputIds($data, 'service_ids', 'service_id') : [];
        $categoryIds = $commissionScope === 'SERVICE' ? $this->inputIds($data, 'service_category_reference_ids', 'service_category_reference_id') : [];
        $retailProductId = $commissionScope === 'RETAIL' ? ($data['retail_product_id'] ?? null) : null;
        $retailCategory = $commissionScope === 'RETAIL' && ! $retailProductId ? trim((string) ($data['retail_category'] ?? '')) ?: null : null;
        $branchId = $data['branch_id'] ?? null;
        $rateValue = round((float) $data['rate_value'], 4);

        if ($calculationType === 'PERCENTAGE' && $rateValue > 100) throw new DomainException('Percentage commission cannot exceed 100%.');
        if ($appliesTo === 'EMPLOYEE' && empty($personnelIds)) throw new DomainException('Select at least one personnel for this commission rule.');

        foreach ($personnelIds as $personnelId) {
            $personnel = Personnel::query()->where('business_id', $businessId)->whereKey($personnelId)->firstOrFail();
            if (! $personnel->commission_eligible || strtoupper((string) $personnel->status) !== 'ACTIVE') throw new DomainException('Selected personnel must be active and commission eligible.');
        }
        if ($branchId) Branch::query()->where('business_id', $businessId)->whereKey($branchId)->firstOrFail();
        foreach ($serviceIds as $serviceId) Service::query()->where('business_id', $businessId)->whereKey($serviceId)->firstOrFail();
        if ($retailProductId) RetailProduct::query()->where('business_id', $businessId)->whereKey($retailProductId)->firstOrFail();
        if ($positionId) $this->assertReferenceValue($businessId, 'EMPLOYEE_POSITION', (int) $positionId);
        foreach ($categoryIds as $categoryId) $this->assertReferenceValue($businessId, 'SERVICE_CATEGORY', (int) $categoryId);

        $effectiveFrom = CarbonImmutable::parse($data['effective_from'])->startOfDay();
        $effectiveUntil = filled($data['effective_until'] ?? null) ? CarbonImmutable::parse($data['effective_until'])->startOfDay() : null;

        return [
            'rule_name' => trim((string) $data['rule_name']),
            'branch_id' => $branchId,
            'applies_to' => $appliesTo,
            'commission_scope' => $commissionScope,
            'personnel_id' => $personnelIds[0] ?? null,
            'personnel_ids' => $personnelIds ?: null,
            'position_reference_id' => $positionId,
            'service_id' => $serviceIds[0] ?? null,
            'service_ids' => $serviceIds ?: null,
            'service_category_reference_id' => $categoryIds[0] ?? null,
            'service_category_reference_ids' => $categoryIds ?: null,
            'retail_product_id' => $retailProductId,
            'retail_category' => $retailCategory,
            'calculation_type' => $calculationType,
            'rate_percentage' => $calculationType === 'PERCENTAGE' ? $rateValue : null,
            'rate_value' => $rateValue,
            'effective_from' => $effectiveFrom->toDateString(),
            'effective_until' => $effectiveUntil?->toDateString(),
            'priority' => (int) ($data['priority'] ?? 0),
            'status' => strtoupper((string) ($data['status'] ?? 'ACTIVE')) === 'INACTIVE' ? 'INACTIVE' : 'ACTIVE',
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
        ];
    }

    private function matches(
        CommissionRule $rule,
        Personnel $personnel,
        Service $service,
        ?int $positionId,
        ?int $serviceCategoryId,
    ): bool {
        $personnelIds = $this->ruleIds($rule->personnel_ids, $rule->personnel_id);
        $staffMatches = match ($rule->applies_to) {
            'EMPLOYEE' => in_array((int) $personnel->id, $personnelIds, true),
            'POSITION' => $positionId && (int) $rule->position_reference_id === (int) $positionId,
            default => true,
        };
        if (! $staffMatches) return false;

        $serviceIds = $this->ruleIds($rule->service_ids, $rule->service_id);
        $categoryIds = $this->ruleIds($rule->service_category_reference_ids, $rule->service_category_reference_id);
        if (empty($serviceIds) && empty($categoryIds)) return true;

        return in_array((int) $service->id, $serviceIds, true)
            || ($serviceCategoryId && in_array((int) $serviceCategoryId, $categoryIds, true));
    }

    private function specificityScore(CommissionRule $rule, int $serviceId, ?int $serviceCategoryId): int
    {
        $staff = match ($rule->applies_to) { 'EMPLOYEE' => 300, 'POSITION' => 200, default => 100 };
        $serviceIds = $this->ruleIds($rule->service_ids, $rule->service_id);
        $categoryIds = $this->ruleIds($rule->service_category_reference_ids, $rule->service_category_reference_id);
        $service = in_array($serviceId, $serviceIds, true) ? 30 : (($serviceCategoryId && in_array((int) $serviceCategoryId, $categoryIds, true)) ? 20 : 10);
        return $staff + $service + ($rule->branch_id ? 2 : 0);
    }

    private function specificityLabel(CommissionRule $rule): string
    {
        $personnelIds = $this->ruleIds($rule->personnel_ids, $rule->personnel_id);
        $serviceIds = $this->ruleIds($rule->service_ids, $rule->service_id);
        $categoryIds = $this->ruleIds($rule->service_category_reference_ids, $rule->service_category_reference_id);
        $staff = match ($rule->applies_to) { 'EMPLOYEE' => count($personnelIds) > 1 ? 'Selected Personnel' : 'Employee', 'POSITION' => 'Position', default => 'All Staff' };
        $service = match (true) {
            ! empty($serviceIds) && ! empty($categoryIds) => 'Selected Services + Categories',
            count($serviceIds) > 1 => 'Selected Services',
            count($serviceIds) === 1 => 'Specific Service',
            ! empty($categoryIds) => count($categoryIds) > 1 ? 'Service Categories' : 'Service Category',
            default => 'All Services',
        };
        return $staff.' + '.$service.($rule->branch_id ? ' + Branch' : '');
    }

    private function retailSpecificityScore(CommissionRule $rule): int
    {
        $staff = match ($rule->applies_to) {
            'EMPLOYEE' => 300,
            'POSITION' => 200,
            default => 100,
        };

        $product = $rule->retail_product_id ? 30 : ($rule->retail_category ? 20 : 10);
        return $staff + $product + ($rule->branch_id ? 2 : 0);
    }

    private function retailSpecificityLabel(CommissionRule $rule): string
    {
        $staff = match ($rule->applies_to) {
            'EMPLOYEE' => count($this->ruleIds($rule->personnel_ids, $rule->personnel_id)) > 1 ? 'Selected Personnel' : 'Employee',
            'POSITION' => 'Position',
            default => 'All Staff',
        };

        $product = $rule->retail_product_id ? 'Specific Product' : ($rule->retail_category ? 'Product Category' : 'All Retail Products');
        return $staff.' + '.$product.($rule->branch_id ? ' + Branch' : '');
    }

    private function inputIds(array $data, string $pluralKey, string $singularKey): array
    {
        $values = $data[$pluralKey] ?? [];
        if (! is_array($values)) $values = [];
        if (empty($values) && ! empty($data[$singularKey])) $values = [$data[$singularKey]];
        return collect($values)->map(fn ($value) => (int) $value)->filter()->unique()->values()->all();
    }

    private function ruleIds(?array $values, mixed $legacyValue = null): array
    {
        $ids = collect($values ?: [])->map(fn ($value) => (int) $value)->filter()->unique()->values()->all();
        if (empty($ids) && $legacyValue) $ids = [(int) $legacyValue];
        return $ids;
    }

    private function referenceValues(int $businessId, string $groupCode): Collection
    {
        $group = ReferenceGroup::query()
            ->where('business_id', $businessId)
            ->where('code', $groupCode)
            ->first();

        if (! $group) {
            return collect();
        }

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
            throw new DomainException('Selected reference value is invalid for this commission rule.');
        }
    }

    private function snapshot(CommissionRule $rule): array
    {
        return [
            'id' => $rule->id,
            'code' => $rule->code,
            'rule_name' => $rule->rule_name,
            'branch_id' => $rule->branch_id,
            'applies_to' => $rule->applies_to,
            'commission_scope' => $rule->commission_scope ?: 'SERVICE',
            'personnel_id' => $rule->personnel_id,
            'personnel_ids' => $this->ruleIds($rule->personnel_ids, $rule->personnel_id),
            'position_reference_id' => $rule->position_reference_id,
            'service_id' => $rule->service_id,
            'service_ids' => $this->ruleIds($rule->service_ids, $rule->service_id),
            'service_category_reference_id' => $rule->service_category_reference_id,
            'service_category_reference_ids' => $this->ruleIds($rule->service_category_reference_ids, $rule->service_category_reference_id),
            'retail_product_id' => $rule->retail_product_id,
            'retail_category' => $rule->retail_category,
            'calculation_type' => $rule->calculation_type,
            'rate_value' => (float) $rule->rate_value,
            'basis' => $rule->basis,
            'effective_from' => $rule->effective_from?->toDateString(),
            'effective_until' => $rule->effective_until?->toDateString(),
            'priority' => (int) $rule->priority,
            'status' => $rule->status,
            'notes' => $rule->notes,
        ];
    }
}
