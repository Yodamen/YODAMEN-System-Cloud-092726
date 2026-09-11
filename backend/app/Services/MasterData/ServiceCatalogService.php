<?php

namespace App\Services\MasterData;

use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\User;
use App\Services\Foundation\BusinessClock;
use App\Services\Foundation\CodeSequenceService;
use App\Services\Security\AuditService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ServiceCatalogService
{
    public function __construct(
        private readonly ReferenceDataService $references,
        private readonly CodeSequenceService $codes,
        private readonly BusinessClock $clock,
        private readonly AuditService $audit,
    ) {
    }

    public function list(int $businessId): Collection
    {
        $today = CarbonImmutable::parse($this->clock->today());

        return Service::query()
            ->where('business_id', $businessId)
            ->with(['category', 'serviceCount', 'statusReference', 'prices' => fn ($query) => $query->orderBy('effective_from')])
            ->orderBy('name')
            ->get()
            ->map(fn (Service $service) => $this->serialize($service, $today));
    }

    public function details(User $actor, Service $service): array
    {
        $this->assertBusiness($actor, $service);

        $service->load(['category', 'serviceCount', 'statusReference', 'prices' => fn ($query) => $query->orderByDesc('effective_from')]);

        return $this->serialize($service, CarbonImmutable::parse($this->clock->today()), true);
    }

    public function create(User $actor, array $data): Service
    {
        return DB::transaction(function () use ($actor, $data) {
            [$category, $count, $status] = $this->resolveReferences($actor->business_id, $data);

            $sessions = $count->code === 'SINGLE_SESSION'
                ? 1
                : max(1, (int) $data['number_of_sessions']);

            try {
                $service = Service::query()->create([
                    'business_id' => $actor->business_id,
                    'code' => $this->codes->next('service', 'SVC-', 6, $actor->business_id),
                    'name' => trim($data['name']),
                    'service_category_reference_id' => $category->id,
                    'service_count_reference_id' => $count->id,
                    'number_of_sessions' => $sessions,
                    'duration_minutes' => max(0, (int) $data['duration_minutes']),
                    'description' => trim((string) ($data['description'] ?? '')) ?: null,
                    'status_reference_id' => $status->id,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);
            } catch (QueryException $exception) {
                if (in_array((string) $exception->getCode(), ['23000', '19'], true)) {
                    throw new DomainException('A service with this name already exists.');
                }

                throw $exception;
            }

            $this->createPrice(
                actor: $actor,
                service: $service,
                amount: (float) $data['initial_price'],
                effectiveFrom: $data['effective_from'],
                scope: 'ALL',
                branchId: null,
                audit: false,
            );

            $service->load(['category', 'serviceCount', 'statusReference', 'prices']);

            $this->audit->record(
                action: 'CREATE_SERVICE',
                module: 'services-pricing',
                entityType: Service::class,
                entityId: $service->id,
                after: $this->snapshot($service),
                actor: $actor,
            );

            return $service;
        });
    }

    public function update(User $actor, Service $service, array $data): Service
    {
        $this->assertBusiness($actor, $service);

        return DB::transaction(function () use ($actor, $service, $data) {
            $service->load(['category', 'serviceCount', 'statusReference']);
            $before = $this->snapshot($service);

            [$category, $count, $status] = $this->resolveReferences($actor->business_id, $data);

            $sessions = $count->code === 'SINGLE_SESSION'
                ? 1
                : max(1, (int) $data['number_of_sessions']);

            try {
                $service->update([
                    'name' => trim($data['name']),
                    'service_category_reference_id' => $category->id,
                    'service_count_reference_id' => $count->id,
                    'number_of_sessions' => $sessions,
                    'duration_minutes' => max(0, (int) $data['duration_minutes']),
                    'description' => trim((string) ($data['description'] ?? '')) ?: null,
                    'status_reference_id' => $status->id,
                    'updated_by' => $actor->id,
                ]);
            } catch (QueryException $exception) {
                if (in_array((string) $exception->getCode(), ['23000', '19'], true)) {
                    throw new DomainException('A service with this name already exists.');
                }

                throw $exception;
            }

            $service->refresh()->load(['category', 'serviceCount', 'statusReference']);

            $this->audit->record(
                action: 'UPDATE_SERVICE',
                module: 'services-pricing',
                entityType: Service::class,
                entityId: $service->id,
                before: $before,
                after: $this->snapshot($service),
                actor: $actor,
            );

            return $service;
        });
    }

    public function changePrice(User $actor, Service $service, array $data): ServicePrice
    {
        $this->assertBusiness($actor, $service);

        $scope = strtoupper(trim((string) ($data['scope'] ?? 'ALL'))) ?: 'ALL';
        $branchId = $data['branch_id'] ?? null;

        if ($scope === 'ALL') {
            $branchId = null;
        }

        return DB::transaction(function () use ($actor, $service, $data, $scope, $branchId) {
            return $this->createPrice(
                actor: $actor,
                service: $service,
                amount: (float) $data['price'],
                effectiveFrom: $data['effective_from'],
                scope: $scope,
                branchId: $branchId,
                audit: true,
            );
        });
    }

    private function createPrice(
        User $actor,
        Service $service,
        float $amount,
        string $effectiveFrom,
        string $scope,
        ?int $branchId,
        bool $audit,
    ): ServicePrice {
        if ($amount < 0) {
            throw new DomainException('Enter a valid price.');
        }

        if (ServicePrice::query()
            ->where('service_id', $service->id)
            ->where('scope', $scope)
            ->whereDate('effective_from', $effectiveFrom)
            ->exists()) {
            throw new DomainException('A price already exists for this effective date.');
        }

        $price = ServicePrice::query()->create([
            'business_id' => $actor->business_id,
            'service_id' => $service->id,
            'code' => $this->codes->next('service-price', 'PRC-', 6, $actor->business_id),
            'scope' => $scope,
            'branch_id' => $branchId,
            'standard_price' => round($amount, 2),
            'effective_from' => $effectiveFrom,
            'status' => 'SCHEDULED',
            'created_by' => $actor->id,
        ]);

        $this->rebuildPricePeriods($service->id, $scope);

        $price->refresh();

        if ($audit) {
            $this->audit->record(
                action: 'CHANGE_SERVICE_PRICE',
                module: 'services-pricing',
                entityType: ServicePrice::class,
                entityId: $price->id,
                after: [
                    'service_code' => $service->code,
                    'price_code' => $price->code,
                    'standard_price' => (string) $price->standard_price,
                    'effective_from' => $price->effective_from?->toDateString(),
                    'scope' => $price->scope,
                ],
                actor: $actor,
            );
        }

        return $price;
    }

    private function rebuildPricePeriods(int $serviceId, string $scope): void
    {
        $rows = ServicePrice::query()
            ->where('service_id', $serviceId)
            ->where('scope', $scope)
            ->orderBy('effective_from')
            ->lockForUpdate()
            ->get();

        $today = CarbonImmutable::parse($this->clock->today());

        foreach ($rows as $index => $row) {
            $next = $rows->get($index + 1);
            $until = $next?->effective_from?->subDay();
            $from = CarbonImmutable::parse($row->effective_from);

            $status = 'ACTIVE';

            if ($from->isAfter($today)) {
                $status = 'SCHEDULED';
            } elseif ($until && CarbonImmutable::parse($until)->isBefore($today)) {
                $status = 'SUPERSEDED';
            }

            $row->forceFill([
                'effective_until' => $until?->toDateString(),
                'status' => $status,
            ])->save();
        }
    }

    private function resolveReferences(int $businessId, array $data): array
    {
        return [
            $this->references->valueByCode($businessId, 'SERVICE_CATEGORY', $data['category_code']),
            $this->references->valueByCode($businessId, 'SERVICE_COUNT', $data['service_count_code']),
            $this->references->valueByCode($businessId, 'SERVICE_STATUS', $data['status_code']),
        ];
    }

    private function assertBusiness(User $actor, Service $service): void
    {
        if ($service->business_id !== $actor->business_id) {
            abort(404);
        }
    }

    private function snapshot(Service $service): array
    {
        return [
            'id' => $service->id,
            'code' => $service->code,
            'name' => $service->name,
            'category_code' => $service->category?->code,
            'service_count_code' => $service->serviceCount?->code,
            'number_of_sessions' => $service->number_of_sessions,
            'duration_minutes' => $service->duration_minutes,
            'status_code' => $service->statusReference?->code,
        ];
    }

    private function serialize(Service $service, CarbonImmutable $today, bool $includeHistory = false): array
    {
        $prices = $service->prices->sortBy('effective_from')->values();

        $current = $prices
            ->filter(fn (ServicePrice $price) => ! CarbonImmutable::parse($price->effective_from)->isAfter($today))
            ->filter(fn (ServicePrice $price) => ! $price->effective_until || ! CarbonImmutable::parse($price->effective_until)->isBefore($today))
            ->last();

        $future = $prices
            ->filter(fn (ServicePrice $price) => CarbonImmutable::parse($price->effective_from)->isAfter($today))
            ->first();

        $payload = [
            'id' => $service->id,
            'code' => $service->code,
            'name' => $service->name,
            'category' => [
                'code' => $service->category?->code,
                'display_value' => $service->category?->display_value,
            ],
            'service_count' => [
                'code' => $service->serviceCount?->code,
                'display_value' => $service->serviceCount?->display_value,
            ],
            'number_of_sessions' => $service->number_of_sessions,
            'duration_minutes' => $service->duration_minutes,
            'description' => $service->description,
            'status' => [
                'code' => $service->statusReference?->code,
                'display_value' => $service->statusReference?->display_value,
            ],
            'current_price' => $current ? [
                'id' => $current->id,
                'code' => $current->code,
                'amount' => (float) $current->standard_price,
                'effective_from' => $current->effective_from?->toDateString(),
                'effective_until' => $current->effective_until?->toDateString(),
                'status' => $current->status,
            ] : null,
            'future_price' => $future ? [
                'id' => $future->id,
                'code' => $future->code,
                'amount' => (float) $future->standard_price,
                'effective_from' => $future->effective_from?->toDateString(),
                'status' => $future->status,
            ] : null,
        ];

        if ($includeHistory) {
            $payload['price_history'] = $prices
                ->sortByDesc('effective_from')
                ->map(fn (ServicePrice $price) => [
                    'id' => $price->id,
                    'code' => $price->code,
                    'amount' => (float) $price->standard_price,
                    'scope' => $price->scope,
                    'branch_id' => $price->branch_id,
                    'effective_from' => $price->effective_from?->toDateString(),
                    'effective_until' => $price->effective_until?->toDateString(),
                    'status' => $price->status,
                ])
                ->values();
        }

        return $payload;
    }
}
