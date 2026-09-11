<?php

namespace App\Services\Operations;

use App\Models\Customer;
use App\Models\User;
use App\Services\Foundation\BusinessClock;
use App\Services\Foundation\CodeSequenceService;
use App\Services\MasterData\ReferenceDataService;
use App\Services\Security\AuditService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class CustomerService
{
    public function __construct(
        private readonly ReferenceDataService $references,
        private readonly CodeSequenceService $codes,
        private readonly AuditService $audit,
        private readonly BusinessClock $clock,
    ) {
    }

    public function save(User $actor, array $data, ?Customer $customer = null): Customer
    {
        if ($customer && $customer->business_id !== $actor->business_id) {
            abort(404);
        }

        return DB::transaction(function () use ($actor, $data, $customer) {
            $mobileNormalized = $this->normalizeMobile($data['mobile_number']);
            if ($mobileNormalized === '') {
                throw new DomainException('Mobile Number is required.');
            }

            $mobileDuplicate = Customer::query()
                ->where('business_id', $actor->business_id)
                ->where('mobile_normalized', $mobileNormalized)
                ->when($customer, fn ($query) => $query->whereKeyNot($customer->id))
                ->exists();

            if ($mobileDuplicate) {
                throw new DomainException('Possible duplicate customer: this mobile number is already registered to another customer.');
            }

            $firstNameNormalized = $this->normalizeName($data['first_name']);
            $lastNameNormalized = $this->normalizeName($data['last_name']);
            $birthday = (string) $data['birthday'];

            $identityDuplicate = Customer::query()
                ->where('business_id', $actor->business_id)
                ->whereDate('birthday', $birthday)
                ->when($customer, fn ($query) => $query->whereKeyNot($customer->id))
                ->get(['id', 'first_name', 'last_name'])
                ->contains(function (Customer $candidate) use ($firstNameNormalized, $lastNameNormalized) {
                    return $this->normalizeName($candidate->first_name) === $firstNameNormalized
                        && $this->normalizeName($candidate->last_name) === $lastNameNormalized;
                });

            if ($identityDuplicate) {
                throw new DomainException('Possible duplicate customer: the same name and birthday are already registered.');
            }

            $genderId = null;
            if (! empty($data['gender_code'])) {
                $genderId = $this->references
                    ->valueByCode($actor->business_id, 'GENDER', $data['gender_code'])
                    ->id;
            }

            $leadSourceId = null;
            if (! empty($data['lead_source_code'])) {
                $leadSourceId = $this->references
                    ->valueByCode($actor->business_id, 'LEAD_SOURCE', $data['lead_source_code'])
                    ->id;
            }

            $before = $customer ? $this->snapshot($customer->load(['gender', 'leadSource'])) : null;
            $isNew = ! $customer;

            if (! $customer) {
                $registrationDate = \Carbon\CarbonImmutable::parse($data['registration_date'], $this->clock->timezone());
                $prefix = 'CUS-'.$registrationDate->format('my').'-';
                $customer = new Customer([
                    'business_id' => $actor->business_id,
                    'code' => $this->codes->next('CUSTOMER', $prefix, 6, $actor->business_id),
                    'status' => 'ACTIVE',
                    'created_by' => $actor->id,
                ]);
            }

            $customer->fill([
                'first_name' => trim($data['first_name']),
                'last_name' => trim($data['last_name']),
                'mobile_number' => trim($data['mobile_number']),
                'mobile_normalized' => $mobileNormalized,
                'email' => $this->nullableTrim($data['email'] ?? null),
                'birthday' => $data['birthday'],
                'gender_reference_id' => $genderId,
                'address' => $this->nullableTrim($data['address'] ?? null),
                'lead_source_reference_id' => $leadSourceId,
                'registration_date' => $data['registration_date'],
                'notes' => $this->nullableTrim($data['notes'] ?? null),
                'updated_by' => $actor->id,
            ]);

            try {
                $customer->save();
            } catch (QueryException $exception) {
                if (in_array((string) $exception->getCode(), ['23000', '19'], true)) {
                    throw new DomainException('This customer conflicts with an existing customer record.');
                }
                throw $exception;
            }

            $customer->refresh()->load(['gender', 'leadSource']);

            $this->audit->record(
                action: $isNew ? 'CREATE' : 'EDIT',
                module: 'customers',
                entityType: Customer::class,
                entityId: $customer->id,
                before: $before,
                after: $this->snapshot($customer),
                actor: $actor,
            );

            return $customer;
        });
    }

    public function toggle(User $actor, Customer $customer): Customer
    {
        if ($customer->business_id !== $actor->business_id) {
            abort(404);
        }

        return DB::transaction(function () use ($actor, $customer) {
            $before = $this->snapshot($customer->load(['gender', 'leadSource']));
            $customer->update([
                'status' => strtoupper($customer->status) === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE',
                'updated_by' => $actor->id,
            ]);
            $customer->refresh()->load(['gender', 'leadSource']);

            $this->audit->record(
                action: $customer->status === 'ACTIVE' ? 'ACTIVATE' : 'DEACTIVATE',
                module: 'customers',
                entityType: Customer::class,
                entityId: $customer->id,
                before: $before,
                after: $this->snapshot($customer),
                actor: $actor,
            );

            return $customer;
        });
    }

    public function snapshot(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'code' => $customer->code,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'mobile_number' => $customer->mobile_number,
            'email' => $customer->email,
            'birthday' => $customer->birthday?->format('Y-m-d'),
            'gender_code' => $customer->gender?->code,
            'address' => $customer->address,
            'lead_source_code' => $customer->leadSource?->code,
            'registration_date' => $customer->registration_date?->format('Y-m-d'),
            'status' => $customer->status,
            'notes' => $customer->notes,
        ];
    }


    public function normalizeName(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return mb_strtolower($value, 'UTF-8');
    }

    public function normalizeMobile(string $value): string
    {
        $number = preg_replace('/\D+/', '', $value) ?? '';
        if (str_starts_with($number, '63') && strlen($number) === 12) {
            $number = '0'.substr($number, 2);
        }
        return $number;
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }
}
