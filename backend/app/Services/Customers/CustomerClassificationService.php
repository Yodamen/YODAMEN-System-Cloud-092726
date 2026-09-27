<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\CustomerClassCriterion;
use App\Models\CustomerTypeCriterion;
use App\Models\Sale;
use App\Models\Visit;
use Illuminate\Support\Collection;

class CustomerClassificationService
{
    /** @var array<int, Collection<int, CustomerTypeCriterion>> */
    private array $typeCache = [];

    /** @var array<int, Collection<int, CustomerClassCriterion>> */
    private array $classCache = [];

    /** @var array<int, array<int, array<string, mixed>>> */
    private array $identityCache = [];

    public function typeFor(int $businessId, int $completedVisits): array
    {
        $completedVisits = max(0, $completedVisits);
        $criterion = $this->typeCriteria($businessId)
            ->filter(fn (CustomerTypeCriterion $row) => $row->active && $row->min_completed_visits <= $completedVisits)
            ->sortByDesc('min_completed_visits')
            ->first();

        if (! $criterion) {
            return ['code' => 'UNCLASSIFIED', 'name' => 'Unclassified', 'color' => '#8A8A8A', 'min_completed_visits' => 0];
        }

        return [
            'id' => $criterion->id,
            'code' => (string) $criterion->code,
            'name' => (string) $criterion->name,
            'color' => $this->normalizeColor($criterion->color),
            'min_completed_visits' => (int) $criterion->min_completed_visits,
        ];
    }

    public function classFor(int $businessId, float $lifetimeValue): ?array
    {
        $lifetimeValue = max(0, round($lifetimeValue, 2));
        $criterion = $this->classCriteria($businessId)
            ->filter(fn (CustomerClassCriterion $row) => $row->active && (float) $row->min_lifetime_value <= $lifetimeValue)
            ->sortByDesc(fn (CustomerClassCriterion $row) => (float) $row->min_lifetime_value)
            ->first();

        if (! $criterion) return null;

        return [
            'id' => $criterion->id,
            'code' => (string) $criterion->code,
            'name' => (string) $criterion->name,
            'min_lifetime_value' => (float) $criterion->min_lifetime_value,
        ];
    }

    public function typeOptions(int $businessId): array
    {
        return $this->typeCriteria($businessId)
            ->filter(fn (CustomerTypeCriterion $row) => $row->active)
            ->sortBy('min_completed_visits')
            ->map(fn (CustomerTypeCriterion $row) => [
                'id' => $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'value' => (string) $row->name,
                'color' => $this->normalizeColor($row->color),
                'min_completed_visits' => (int) $row->min_completed_visits,
            ])
            ->values()
            ->all();
    }

    public function classOptions(int $businessId): array
    {
        return $this->classCriteria($businessId)
            ->filter(fn (CustomerClassCriterion $row) => $row->active)
            ->sortBy('min_lifetime_value')
            ->map(fn (CustomerClassCriterion $row) => [
                'id' => $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'value' => (string) $row->name,
                'min_lifetime_value' => (float) $row->min_lifetime_value,
            ])
            ->values()
            ->all();
    }

    public function clear(int $businessId): void
    {
        unset($this->typeCache[$businessId], $this->classCache[$businessId], $this->identityCache[$businessId]);
    }

    /**
     * Return Customer Type + Customer Class identity for one or more customers.
     * Type is visit-based; Class is Lifetime Value-based.
     *
     * @param array<int, int|string> $customerIds
     * @return array<int, array<string, mixed>>
     */
    public function identitiesForCustomerIds(int $businessId, array $customerIds): array
    {
        $ids = collect($customerIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $cached = collect($ids)->filter(fn (int $id) => isset($this->identityCache[$businessId][$id]));
        $missing = $ids->diff($cached)->values();

        if ($missing->isNotEmpty()) {
            $visitCounts = Visit::query()
                ->where('business_id', $businessId)
                ->whereIn('customer_id', $missing)
                ->where('status', 'COMPLETED')
                ->selectRaw('customer_id, count(*) as aggregate')
                ->groupBy('customer_id')
                ->pluck('aggregate', 'customer_id');

            $sales = Sale::query()
                ->where('business_id', $businessId)
                ->whereIn('customer_id', $missing)
                ->whereNotNull('financial_finalized_at')
                ->where('status', '<>', 'REVERSED')
                ->with(['payments' => fn ($query) => $query
                    ->where('status', 'RECEIVED')
                    ->where('payment_type', '!=', 'TIP')])
                ->get();

            foreach ($missing as $customerId) {
                $completedVisits = (int) ($visitCounts[$customerId] ?? 0);
                $lifetimeValue = 0.0;

                foreach ($sales->where('customer_id', $customerId) as $sale) {
                    $received = (float) $sale->payments->sum(fn ($payment) => (float) $payment->amount);
                    $saleValue = (float) ($sale->effective_net_amount ?? $sale->net_amount ?? $sale->gross_amount);
                    $lifetimeValue += min($saleValue, $received);
                }

                $type = $this->typeFor($businessId, $completedVisits);
                $class = $this->classFor($businessId, $lifetimeValue);

                $this->identityCache[$businessId][$customerId] = [
                    'customer_id' => $customerId,
                    'customer_type' => $type['name'],
                    'customer_type_code' => $type['code'],
                    'customer_type_color' => $type['color'],
                    'customer_class' => $class['name'] ?? 'Unclassified',
                    'customer_class_code' => $class['code'] ?? null,
                    'completed_visits' => $completedVisits,
                    'lifetime_value' => round($lifetimeValue, 2),
                ];
            }
        }

        return $ids->mapWithKeys(fn (int $id) => [$id => $this->identityCache[$businessId][$id]])->all();
    }

    public function identityForCustomer(Customer $customer): array
    {
        return $this->identitiesForCustomerIds((int) $customer->business_id, [(int) $customer->id])[(int) $customer->id];
    }

    private function normalizeColor(?string $color): string
    {
        $value = strtoupper(trim((string) $color));

        return preg_match('/^#[0-9A-F]{6}$/', $value) ? $value : '#343434';
    }

    private function typeCriteria(int $businessId): Collection
    {
        return $this->typeCache[$businessId] ??= CustomerTypeCriterion::query()
            ->where('business_id', $businessId)
            ->orderBy('min_completed_visits')
            ->orderBy('sort_order')
            ->get();
    }

    private function classCriteria(int $businessId): Collection
    {
        return $this->classCache[$businessId] ??= CustomerClassCriterion::query()
            ->where('business_id', $businessId)
            ->orderBy('min_lifetime_value')
            ->orderBy('sort_order')
            ->get();
    }
}
