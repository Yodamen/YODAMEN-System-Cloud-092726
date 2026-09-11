<?php

namespace App\Services\MasterData;

use App\Models\Branch;
use App\Models\Personnel;
use App\Models\User;
use App\Services\Foundation\CodeSequenceService;
use App\Services\Security\AuditService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class PersonnelService
{
    public function __construct(
        private readonly ReferenceDataService $references,
        private readonly CodeSequenceService $codes,
        private readonly AuditService $audit,
    ) {
    }

    public function save(User $actor, array $data, ?Personnel $personnel = null): Personnel
    {
        if ($personnel && $personnel->business_id !== $actor->business_id) {
            abort(404);
        }

        return DB::transaction(function () use ($actor, $data, $personnel) {
            $position = $this->references->valueByCode($actor->business_id, 'EMPLOYEE_POSITION', $data['position_code']);
            $personnelType = $this->references->valueByCode($actor->business_id, 'PERSONNEL_TYPE', $data['personnel_type_code']);
            $compRule = $this->references->valueByCode($actor->business_id, 'COMPENSATION_RULE', $data['compensation_rule_code']);

            if ($position->code === 'MANAGING_PARTNER') {
                $personnelType = $this->references->valueByCode($actor->business_id, 'PERSONNEL_TYPE', 'PARTNER');
                $data['profit_sharing_eligible'] = true;
            }

            $employmentType = null;
            if ($personnelType->code === 'EMPLOYEE') {
                $employmentType = $this->references->valueByCode(
                    $actor->business_id,
                    'EMPLOYMENT_TYPE',
                    ($data['employment_type_code'] ?? null) ?: 'REGULAR'
                );
            }

            $this->validateRules($actor, $data, $position->code, $personnelType->code, $compRule->code, $personnel?->id);

            $before = $personnel ? $this->snapshot($personnel->loadMissing(['position', 'personnelType', 'employmentType', 'compensationRule'])) : null;

            $attributes = [
                'business_id' => $actor->business_id,
                'first_name' => trim($data['first_name']),
                'last_name' => trim($data['last_name']),
                'mobile_number' => trim((string) ($data['mobile_number'] ?? '')) ?: null,
                'email' => strtolower(trim((string) ($data['email'] ?? ''))) ?: null,
                'position_reference_id' => $position->id,
                'branch_id' => $data['branch_id'] ?? null,
                'employment_type_reference_id' => $employmentType?->id,
                'personnel_type_reference_id' => $personnelType->id,
                'profit_sharing_eligible' => (bool) $data['profit_sharing_eligible'],
                'profit_sharing_percentage' => (bool) $data['profit_sharing_eligible']
                    ? round((float) ($data['profit_sharing_percentage'] ?? 0), 4)
                    : 0,
                'profit_sharing_effective_from' => (bool) $data['profit_sharing_eligible']
                    ? ($data['profit_sharing_effective_from'] ?? null)
                    : null,
                'profit_sharing_effective_until' => (bool) $data['profit_sharing_eligible']
                    ? ($data['profit_sharing_effective_until'] ?? null)
                    : null,
                'hourly_rate' => $compRule->code === 'NONE' ? 0 : round((float) $data['hourly_rate'], 2),
                'ot_entitled' => $compRule->code === 'NONE' ? false : (bool) $data['ot_entitled'],
                'ot_multiplier' => $compRule->code === 'NONE' || ! (bool) $data['ot_entitled']
                    ? null
                    : round((float) $data['ot_multiplier'], 3),
                'compensation_rule_reference_id' => $compRule->id,
                'guaranteed_daily_pay' => $compRule->code === 'GUARANTEED_VS_COMMISSION'
                    ? round((float) $data['guaranteed_daily_pay'], 2)
                    : 0,
                'can_perform_services' => (bool) $data['can_perform_services'],
                'commission_eligible' => (bool) $data['commission_eligible'],
                'hire_date' => $data['hire_date'] ?? null,
                'linked_user_id' => $data['linked_user_id'] ?? null,
                'status' => strtoupper($data['status']),
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                'updated_by' => $actor->id,
            ];

            try {
                if ($personnel) {
                    $personnel->update($attributes);
                } else {
                    $personnel = Personnel::query()->create(array_merge($attributes, [
                        'code' => $this->codes->next('personnel', 'EMP-', 6, $actor->business_id),
                        'created_by' => $actor->id,
                    ]));
                }
            } catch (QueryException $exception) {
                if (in_array((string) $exception->getCode(), ['23000', '19'], true)) {
                    throw new DomainException('The selected user account is already linked to another personnel record.');
                }

                throw $exception;
            }

            $personnel->refresh()->load(['position', 'branch', 'employmentType', 'personnelType', 'compensationRule', 'linkedUser']);

            $this->audit->record(
                action: $before ? 'EDIT_EMPLOYEE' : 'CREATE_EMPLOYEE',
                module: 'employees',
                entityType: Personnel::class,
                entityId: $personnel->id,
                before: $before,
                after: $this->snapshot($personnel),
                actor: $actor,
            );

            return $personnel;
        });
    }

    public function serialize(Personnel $personnel): array
    {
        return [
            'id' => $personnel->id,
            'code' => $personnel->code,
            'first_name' => $personnel->first_name,
            'last_name' => $personnel->last_name,
            'full_name' => $personnel->full_name,
            'mobile_number' => $personnel->mobile_number,
            'email' => $personnel->email,
            'position' => $this->refPayload($personnel->position),
            'branch' => $personnel->branch ? [
                'id' => $personnel->branch->id,
                'code' => $personnel->branch->code,
                'name' => $personnel->branch->name,
            ] : null,
            'employment_type' => $this->refPayload($personnel->employmentType),
            'personnel_type' => $this->refPayload($personnel->personnelType),
            'profit_sharing_eligible' => $personnel->profit_sharing_eligible,
            'profit_sharing_percentage' => (float) $personnel->profit_sharing_percentage,
            'profit_sharing_effective_from' => $personnel->profit_sharing_effective_from?->toDateString(),
            'profit_sharing_effective_until' => $personnel->profit_sharing_effective_until?->toDateString(),
            'hourly_rate' => (float) $personnel->hourly_rate,
            'ot_entitled' => $personnel->ot_entitled,
            'ot_multiplier' => $personnel->ot_multiplier !== null ? (float) $personnel->ot_multiplier : null,
            'compensation_rule' => $this->refPayload($personnel->compensationRule),
            'guaranteed_daily_pay' => (float) $personnel->guaranteed_daily_pay,
            'can_perform_services' => $personnel->can_perform_services,
            'commission_eligible' => $personnel->commission_eligible,
            'hire_date' => $personnel->hire_date?->toDateString(),
            'linked_user' => $personnel->linkedUser ? [
                'id' => $personnel->linkedUser->id,
                'code' => $personnel->linkedUser->code,
                'username' => $personnel->linkedUser->username,
                'display_name' => $personnel->linkedUser->display_name,
            ] : null,
            'status' => $personnel->status,
            'notes' => $personnel->notes,
        ];
    }

    private function validateRules(
        User $actor,
        array $data,
        string $positionCode,
        string $personnelTypeCode,
        string $compRuleCode,
        ?int $personnelId,
    ): void {
        if ($positionCode === 'MANAGING_PARTNER' && $personnelTypeCode !== 'PARTNER') {
            throw new DomainException('Managing Partner must use Personnel Type: Partner.');
        }

        if ($personnelTypeCode !== 'PARTNER' && (bool) $data['profit_sharing_eligible']) {
            throw new DomainException('Profit Sharing can only be enabled for Personnel Type: Partner.');
        }

        if ((bool) $data['profit_sharing_eligible']) {
            $percentage = (float) ($data['profit_sharing_percentage'] ?? 0);

            if ($percentage <= 0 || $percentage > 100 || empty($data['profit_sharing_effective_from'])) {
                throw new DomainException('Profit Sharing Percentage and Effective From are required for an eligible Partner.');
            }
        }

        if ($compRuleCode === 'GUARANTEED_VS_COMMISSION' && (float) $data['guaranteed_daily_pay'] <= 0) {
            throw new DomainException('Guaranteed Daily Pay is required for Guaranteed vs Commission personnel.');
        }

        if ($compRuleCode !== 'NONE' && (bool) $data['ot_entitled']) {
            $multiplier = $data['ot_multiplier'] ?? null;

            if ($multiplier === null || (float) $multiplier < 1) {
                throw new DomainException('OT Multiplier is required and must be at least 1.00 when OT Entitled is enabled.');
            }
        }

        if (! empty($data['branch_id'])) {
            $validBranch = Branch::query()
                ->where('id', $data['branch_id'])
                ->where('business_id', $actor->business_id)
                ->where('status', 'ACTIVE')
                ->exists();

            if (! $validBranch) {
                throw new DomainException('Selected branch is invalid or inactive.');
            }
        }

        if (! empty($data['linked_user_id'])) {
            $validUser = User::query()
                ->where('id', $data['linked_user_id'])
                ->where('business_id', $actor->business_id)
                ->exists();

            if (! $validUser) {
                throw new DomainException('Selected linked user is invalid.');
            }

            $query = Personnel::query()->where('linked_user_id', $data['linked_user_id']);
            if ($personnelId) {
                $query->whereKeyNot($personnelId);
            }

            if ($query->exists()) {
                throw new DomainException('That user account is already linked to another personnel record.');
            }
        }
    }

    private function snapshot(Personnel $personnel): array
    {
        return [
            'id' => $personnel->id,
            'code' => $personnel->code,
            'name' => $personnel->full_name,
            'position_code' => $personnel->position?->code,
            'personnel_type_code' => $personnel->personnelType?->code,
            'employment_type_code' => $personnel->employmentType?->code,
            'compensation_rule_code' => $personnel->compensationRule?->code,
            'hourly_rate' => (string) $personnel->hourly_rate,
            'ot_entitled' => $personnel->ot_entitled,
            'ot_multiplier' => $personnel->ot_multiplier !== null ? (string) $personnel->ot_multiplier : null,
            'profit_sharing_eligible' => $personnel->profit_sharing_eligible,
            'profit_sharing_percentage' => (string) $personnel->profit_sharing_percentage,
            'status' => $personnel->status,
        ];
    }

    private function refPayload($reference): ?array
    {
        return $reference ? [
            'id' => $reference->id,
            'code' => $reference->code,
            'display_value' => $reference->display_value,
        ] : null;
    }
}
