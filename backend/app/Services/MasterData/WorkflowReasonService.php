<?php

namespace App\Services\MasterData;

use App\Models\ReferenceValue;
use App\Models\User;
use DomainException;

class WorkflowReasonService
{
    public function options(User $actor, string $context): array
    {
        $context = strtoupper(trim($context));
        if (! WorkflowReasonCatalog::validContext($context)) {
            throw new DomainException('Unsupported workflow reason context.');
        }

        return ReferenceValue::query()
            ->where('active', true)
            ->whereHas('group', fn ($group) => $group
                ->where('business_id', $actor->business_id)
                ->where('code', WorkflowReasonCatalog::GROUP_CODE)
                ->where('active', true))
            ->orderBy('sort_order')
            ->orderBy('display_value')
            ->get(['id', 'code', 'display_value', 'metadata'])
            ->filter(fn (ReferenceValue $value) => in_array($context, $this->contextsFor($value), true))
            ->map(fn (ReferenceValue $value) => $this->serialize($value))
            ->values()
            ->all();
    }

    public function resolve(User $actor, int $referenceId, string $context): ReferenceValue
    {
        $context = strtoupper(trim($context));
        if (! WorkflowReasonCatalog::validContext($context)) {
            throw new DomainException('Unsupported workflow reason context.');
        }

        $value = ReferenceValue::query()
            ->whereKey($referenceId)
            ->where('active', true)
            ->whereHas('group', fn ($group) => $group
                ->where('business_id', $actor->business_id)
                ->where('code', WorkflowReasonCatalog::GROUP_CODE)
                ->where('active', true))
            ->first();

        if (! $value || ! in_array($context, $this->contextsFor($value), true)) {
            throw new DomainException('Select a valid standardized reason for this workflow.');
        }

        return $value;
    }

    public function resolveByCode(User $actor, string $code, string $context): ReferenceValue
    {
        $code = strtoupper(trim($code));
        $context = strtoupper(trim($context));
        if ($code === '') {
            throw new DomainException('Select a standardized reason.');
        }

        $value = ReferenceValue::query()
            ->where('code', $code)
            ->where('active', true)
            ->whereHas('group', fn ($group) => $group
                ->where('business_id', $actor->business_id)
                ->where('code', WorkflowReasonCatalog::GROUP_CODE)
                ->where('active', true))
            ->first();

        if (! $value || ! in_array($context, $this->contextsFor($value), true)) {
            throw new DomainException('Select a valid standardized reason for this workflow.');
        }

        return $value;
    }

    public function contextsFor(ReferenceValue $value): array
    {
        $metadata = is_array($value->metadata) ? $value->metadata : [];
        return collect($metadata['contexts'] ?? [])
            ->map(fn ($context) => strtoupper(trim((string) $context)))
            ->filter(fn ($context) => WorkflowReasonCatalog::validContext($context))
            ->unique()
            ->values()
            ->all();
    }

    public function serialize(?ReferenceValue $value): ?array
    {
        if (! $value) return null;
        return [
            'id' => (int) $value->id,
            'code' => $value->code,
            'name' => $value->display_value,
            'contexts' => $this->contextsFor($value),
        ];
    }
}
