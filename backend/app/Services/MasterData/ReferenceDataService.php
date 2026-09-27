<?php

namespace App\Services\MasterData;

use App\Models\ReferenceGroup;
use App\Models\ReferenceValue;
use App\Models\User;
use App\Services\Security\AuditService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ReferenceDataService
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    public function groupByCode(int $businessId, string $groupCode): ReferenceGroup
    {
        return ReferenceGroup::query()
            ->where('business_id', $businessId)
            ->where('code', strtoupper(trim($groupCode)))
            ->firstOrFail();
    }

    public function valueByCode(int $businessId, string $groupCode, string $valueCode, bool $requireActive = true): ReferenceValue
    {
        $group = $this->groupByCode($businessId, $groupCode);

        $query = ReferenceValue::query()
            ->where('reference_group_id', $group->id)
            ->where('code', strtoupper(trim($valueCode)));

        if ($requireActive) {
            $query->where('active', true);
        }

        return $query->firstOrFail();
    }

    public function create(User $actor, array $data): ReferenceValue
    {
        return DB::transaction(function () use ($actor, $data) {
            $group = $this->groupByCode($actor->business_id, $data['group_code']);

            try {
                $value = ReferenceValue::query()->create([
                    'reference_group_id' => $group->id,
                    'code' => strtoupper(trim($data['code'])),
                    'display_value' => trim($data['display_value']),
                    'sort_order' => (int) ($data['sort_order'] ?? 0),
                    'is_system' => false,
                    'active' => true,
                    'created_by' => $actor->id,
                    'metadata' => $this->normalizedMetadata($group, $data),
                    'updated_by' => $actor->id,
                ]);
            } catch (QueryException $exception) {
                if (in_array((string) $exception->getCode(), ['23000', '19'], true)) {
                    throw new DomainException('This reference code or display value already exists in the selected category.');
                }

                throw $exception;
            }

            $this->audit->record(
                action: 'CREATE',
                module: 'references',
                entityType: ReferenceValue::class,
                entityId: $value->id,
                after: $this->snapshot($value->load('group')),
                actor: $actor,
            );

            return $value->load('group');
        });
    }

    public function update(User $actor, ReferenceValue $value, array $data): ReferenceValue
    {
        $value->load('group');

        if ($value->group->business_id !== $actor->business_id) {
            abort(404);
        }

        $targetGroup = $this->groupByCode($actor->business_id, $data['group_code']);
        $before = $this->snapshot($value);

        if ($targetGroup->id !== $value->reference_group_id) {
            throw new DomainException('Reference category cannot be changed after an option is created.');
        }

        if ($value->is_system && strtoupper(trim($data['code'])) !== $value->code) {
            throw new DomainException('System reference codes are fixed. You may change the display value only.');
        }

        $metadata = $value->is_system && strtoupper((string) $targetGroup->code) === WorkflowReasonCatalog::GROUP_CODE
            ? $value->metadata
            : $this->normalizedMetadata($targetGroup, $data, $value->metadata);

        try {
            $value->update([
                'reference_group_id' => $targetGroup->id,
                'code' => strtoupper(trim($data['code'])),
                'display_value' => trim($data['display_value']),
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'metadata' => $metadata,
                'updated_by' => $actor->id,
            ]);
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '19'], true)) {
                throw new DomainException('This reference code or display value already exists in the selected category.');
            }

            throw $exception;
        }

        $value->refresh()->load('group');

        $this->audit->record(
            action: 'EDIT',
            module: 'references',
            entityType: ReferenceValue::class,
            entityId: $value->id,
            before: $before,
            after: $this->snapshot($value),
            actor: $actor,
        );

        return $value;
    }

    public function toggle(User $actor, ReferenceValue $value): ReferenceValue
    {
        $value->load('group');

        if ($value->group->business_id !== $actor->business_id) {
            abort(404);
        }

        $before = $this->snapshot($value);
        $value->update([
            'active' => ! $value->active,
            'updated_by' => $actor->id,
        ]);
        $value->refresh()->load('group');

        $this->audit->record(
            action: $value->active ? 'ACTIVATE' : 'DEACTIVATE',
            module: 'references',
            entityType: ReferenceValue::class,
            entityId: $value->id,
            before: $before,
            after: $this->snapshot($value),
            actor: $actor,
        );

        return $value;
    }

    public function snapshot(ReferenceValue $value): array
    {
        return [
            'id' => $value->id,
            'group_code' => $value->group?->code,
            'group_name' => $value->group?->name,
            'code' => $value->code,
            'display_value' => $value->display_value,
            'sort_order' => $value->sort_order,
            'is_system' => $value->is_system,
            'active' => $value->active,
            'metadata' => $value->metadata,
        ];
    }

    private function normalizedMetadata(ReferenceGroup $group, array $data, ?array $existing = null): ?array
    {
        if (strtoupper((string) $group->code) !== WorkflowReasonCatalog::GROUP_CODE) {
            return $existing;
        }

        $contexts = collect($data['metadata']['contexts'] ?? [])
            ->map(fn ($context) => strtoupper(trim((string) $context)))
            ->filter(fn ($context) => WorkflowReasonCatalog::validContext($context))
            ->unique()
            ->values()
            ->all();

        if ($contexts === []) {
            throw new DomainException('Select at least one applicable workflow for a standardized reason.');
        }

        return ['contexts' => $contexts];
    }

}
