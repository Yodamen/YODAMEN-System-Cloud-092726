<?php

use App\Models\Customer;
use App\Models\ReferenceGroup;
use App\Models\Service;
use App\Services\Customers\CustomerClassificationService;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    $this->seed(FoundationSeeder::class);
    $this->seed(MasterDataSeeder::class);
});

it('allows customer types to use configurable names counts and visit thresholds', function () {
    $user = foundationUser();

    $response = $this->actingAs($user)->putJson('/api/v1/customers/type-criteria', [
        'criteria' => [
            ['name' => 'First Visit', 'color' => '#9A9388', 'min_completed_visits' => 0, 'active' => true],
            ['name' => 'Returning', 'color' => '#665C52', 'min_completed_visits' => 2, 'active' => true],
            ['name' => 'Frequent', 'color' => '#8A6A3E', 'min_completed_visits' => 5, 'active' => true],
            ['name' => 'VIP', 'color' => '#242424', 'min_completed_visits' => 20, 'active' => true],
            ['name' => 'Elite', 'color' => '#4B5563', 'min_completed_visits' => 50, 'active' => true],
        ],
    ])->assertOk()->assertJsonCount(5, 'criteria');

    $response->assertJsonPath('criteria.0.name', 'First Visit')
        ->assertJsonPath('criteria.0.color', '#9A9388')
        ->assertJsonPath('criteria.2.name', 'Frequent')
        ->assertJsonPath('criteria.2.color', '#8A6A3E')
        ->assertJsonPath('criteria.4.name', 'Elite');

    $classification = app(CustomerClassificationService::class);
    $classification->clear((int) $user->business_id);

    expect($classification->typeFor($user->business_id, 0)['name'])->toBe('First Visit')
        ->and($classification->typeFor($user->business_id, 3)['name'])->toBe('Returning')
        ->and($classification->typeFor($user->business_id, 8)['name'])->toBe('Frequent')
        ->and($classification->typeFor($user->business_id, 8)['color'])->toBe('#8A6A3E')
        ->and($classification->typeFor($user->business_id, 25)['name'])->toBe('VIP')
        ->and($classification->typeFor($user->business_id, 60)['name'])->toBe('Elite');
});

it('allows customer classes to be configured from lifetime value thresholds', function () {
    $user = foundationUser();

    $this->actingAs($user)->putJson('/api/v1/customers/class-criteria', [
        'criteria' => [
            ['name' => 'Silver', 'min_lifetime_value' => 5000, 'active' => true],
            ['name' => 'Gold', 'min_lifetime_value' => 15000, 'active' => true],
            ['name' => 'Platinum', 'min_lifetime_value' => 30000, 'active' => true],
        ],
    ])->assertOk()->assertJsonCount(3, 'criteria');

    $classification = app(CustomerClassificationService::class);
    $classification->clear((int) $user->business_id);

    expect($classification->classFor($user->business_id, 4999))->toBeNull()
        ->and($classification->classFor($user->business_id, 5000)['name'])->toBe('Silver')
        ->and($classification->classFor($user->business_id, 20000)['name'])->toBe('Gold')
        ->and($classification->classFor($user->business_id, 35000)['name'])->toBe('Platinum');
});

it('auto detects booking source and customer type instead of trusting form values', function () {
    $user = foundationUser();
    $groups = ReferenceGroup::query()->with('values')->get()->keyBy('code');
    $service = Service::query()->create([
        'business_id' => $user->business_id,
        'code' => 'SRV-CUST-001',
        'name' => 'Classification Test Service',
        'service_category_reference_id' => $groups['SERVICE_CATEGORY']->values->firstWhere('code', 'HAIRCUT')->id,
        'service_count_reference_id' => $groups['SERVICE_COUNT']->values->firstWhere('code', 'SINGLE_SESSION')->id,
        'number_of_sessions' => 1,
        'duration_minutes' => 30,
        'status_reference_id' => $groups['SERVICE_STATUS']->values->firstWhere('code', 'ACTIVE')->id,
    ]);
    $customer = Customer::query()->create([
        'business_id' => $user->business_id,
        'code' => 'CUS-0926-009999',
        'first_name' => 'Auto',
        'last_name' => 'Detected',
        'mobile_number' => '09179999999',
        'mobile_normalized' => '09179999999',
        'registration_date' => '2026-09-26',
        'status' => 'ACTIVE',
    ]);

    $response = $this->actingAs($user)->postJson('/api/v1/operations/bookings', [
        'customer_id' => $customer->id,
        'customer_type' => 'LOYAL',
        'booking_source' => 'RESERVATION',
        'booking_date' => '2026-09-27',
        'booking_time' => '10:00',
        'services' => [['service_id' => $service->id, 'quantity' => 1]],
        'retail_items' => [],
    ])->assertCreated();

    $response->assertJsonPath('booking.customer_type', 'NEW')
        ->assertJsonPath('booking.booking_source', 'RESERVATION');
});

it('rejects overlapping active customer type thresholds', function () {
    $user = foundationUser();

    $this->actingAs($user)->putJson('/api/v1/customers/type-criteria', [
        'criteria' => [
            ['name' => 'First Visit', 'color' => '#9A9388', 'min_completed_visits' => 0, 'active' => true],
            ['name' => 'Returning', 'color' => '#665C52', 'min_completed_visits' => 2, 'active' => true],
            ['name' => 'Frequent', 'color' => '#8A6A3E', 'min_completed_visits' => 2, 'active' => true],
        ],
    ])->assertStatus(422);
});
