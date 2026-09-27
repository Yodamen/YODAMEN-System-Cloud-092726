<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\ReferenceGroup;
use App\Models\ReferenceValue;
use Illuminate\Database\Seeder;

class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        $business = Business::query()->where('code', 'YDM')->firstOrFail();

        foreach ($this->catalog() as $groupDefinition) {
            $group = ReferenceGroup::query()->updateOrCreate(
                [
                    'business_id' => $business->id,
                    'code' => $groupDefinition['code'],
                ],
                [
                    'name' => $groupDefinition['name'],
                    'description' => $groupDefinition['description'] ?? null,
                    'is_system' => true,
                    'active' => true,
                ]
            );

            if ($group->values()->exists()) {
                continue;
            }

            foreach ($groupDefinition['values'] as $index => [$code, $displayValue]) {
                ReferenceValue::query()->create([
                    'reference_group_id' => $group->id,
                    'code' => $code,
                    'display_value' => $displayValue,
                    'sort_order' => ($index + 1) * 10,
                    'is_system' => true,
                    'active' => true,
                ]);
            }
        }
    }

    private function catalog(): array
    {
        return [
            [
                'code' => 'BOOKING_SOURCE',
                'name' => 'Booking Source',
                'values' => [
                    ['RESERVATION', 'Reservation'],
                    ['WALK_IN', 'Walk-in'],
                ],
            ],
            [
                'code' => 'GENDER',
                'name' => 'Gender',
                'values' => [
                    ['MALE', 'Male'],
                    ['FEMALE', 'Female'],
                    ['PREFER_NOT_TO_SAY', 'Prefer not to say'],
                ],
            ],
            [
                'code' => 'LEAD_SOURCE',
                'name' => 'Lead Source',
                'values' => [
                    ['FACEBOOK', 'Facebook'],
                    ['TIKTOK', 'TikTok'],
                    ['INSTAGRAM', 'Instagram'],
                    ['WEBSITE', 'Website'],
                    ['REFERRAL', 'Referral'],
                    ['PRINT_MATERIALS', 'Print Materials'],
                    ['SIGNAGES', 'Signages'],
                ],
            ],
            [
                'code' => 'PAYMENT_METHOD',
                'name' => 'Payment Method',
                'values' => [],
            ],
            [
                'code' => 'SERVICE_CATEGORY',
                'name' => 'Service Category',
                'values' => [
                    ['HAIRCUT', 'Haircut'],
                    ['ALA_CARTE', 'Ala Carte'],
                    ['SIGNATURE', 'Signature'],
                    ['PACKAGE', 'Package'],
                    ['ADD_ON', 'Add-On'],
                ],
            ],
            [
                'code' => 'SERVICE_COUNT',
                'name' => 'Service Count',
                'values' => [
                    ['SINGLE_SESSION', 'Single Session'],
                    ['MULTI_SESSION', 'Multiple Sessions'],
                ],
            ],
            [
                'code' => 'SERVICE_STATUS',
                'name' => 'Service Status',
                'values' => [
                    ['ACTIVE', 'Active'],
                    ['INACTIVE', 'Inactive'],
                ],
            ],
            [
                'code' => 'EMPLOYEE_POSITION',
                'name' => 'Employee Position',
                'values' => [
                    ['BARBER', 'Barber'],
                    ['THERAPIST', 'Therapist'],
                    ['RECEPTIONIST', 'Receptionist'],
                    ['SUPERVISOR', 'Supervisor'],
                    ['MANAGER', 'Manager'],
                    ['MANAGING_PARTNER', 'Managing Partner'],
                    ['OTHER', 'Other'],
                ],
            ],
            [
                'code' => 'EMPLOYMENT_TYPE',
                'name' => 'Employment Type',
                'values' => [
                    ['REGULAR', 'Regular'],
                    ['PROBATIONARY', 'Probationary'],
                    ['PART_TIME', 'Part-Time'],
                    ['CONTRACTUAL', 'Contractual'],
                ],
            ],
            [
                'code' => 'PERSONNEL_TYPE',
                'name' => 'Personnel Type',
                'values' => [
                    ['EMPLOYEE', 'Employee'],
                    ['PARTNER', 'Partner'],
                ],
            ],
            [
                'code' => 'COMPENSATION_RULE',
                'name' => 'Compensation Rule',
                'values' => [
                    ['HOURLY_PLUS_COMMISSION', 'Hourly + Commission'],
                    ['GUARANTEED_VS_COMMISSION', 'Guaranteed Daily Pay vs Commission'],
                    ['NONE', 'No Operational Compensation'],
                ],
            ],
            [
                'code' => 'ATTENDANCE_STATUS',
                'name' => 'Attendance Status',
                'values' => [
                    ['PRESENT', 'Present'],
                    ['ABSENT', 'Absent'],
                    ['LEAVE', 'Leave'],
                    ['OFF', 'Day Off'],
                ],
            ],
            [
                'code' => 'HR_ADJUSTMENT_TYPE',
                'name' => 'HR Adjustment Type',
                'values' => [
                    ['ALLOWANCE', 'Allowance'],
                    ['INCENTIVE', 'Incentive'],
                    ['BONUS', 'Bonus'],
                    ['DEDUCTION', 'Deduction'],
                    ['OTHER', 'Other'],
                ],
            ],
            [
                'code' => 'LEAVE_TYPE',
                'name' => 'Leave Type',
                'values' => [
                    ['VACATION', 'Vacation Leave'],
                    ['SICK', 'Sick Leave'],
                    ['EMERGENCY', 'Emergency Leave'],
                    ['UNPAID', 'Unpaid Leave'],
                    ['OTHER', 'Other'],
                ],
            ],
        ];
    }
}
