<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Personnel;
use App\Models\ReferenceGroup;
use App\Models\Service;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    $this->seed(FoundationSeeder::class);
    $this->seed(MasterDataSeeder::class);
});

function bookingFixture(): array
{
    $user = foundationUser();
    $groups = ReferenceGroup::query()->with('values')->get()->keyBy('code');
    $service = Service::query()->create([
        'business_id' => $user->business_id,
        'code' => 'SRV-TEST-001',
        'name' => 'Test Haircut',
        'service_category_reference_id' => $groups['SERVICE_CATEGORY']->values->firstWhere('code', 'HAIRCUT')->id,
        'service_count_reference_id' => $groups['SERVICE_COUNT']->values->firstWhere('code', 'SINGLE_SESSION')->id,
        'number_of_sessions' => 1,
        'duration_minutes' => 45,
        'status_reference_id' => $groups['SERVICE_STATUS']->values->firstWhere('code', 'ACTIVE')->id,
    ]);
    $personnel = Personnel::query()->create([
        'business_id' => $user->business_id,
        'code' => 'EMP-TEST-001',
        'first_name' => 'Staff',
        'last_name' => 'Member',
        'position_reference_id' => $groups['EMPLOYEE_POSITION']->values->firstWhere('code', 'BARBER')->id,
        'personnel_type_reference_id' => $groups['PERSONNEL_TYPE']->values->firstWhere('code', 'EMPLOYEE')->id,
        'compensation_rule_reference_id' => $groups['COMPENSATION_RULE']->values->firstWhere('code', 'HOURLY_PLUS_COMMISSION')->id,
        'hourly_rate' => 100,
        'can_perform_services' => true,
        'commission_eligible' => true,
        'status' => 'ACTIVE',
    ]);
    $customer = Customer::query()->create([
        'business_id' => $user->business_id,
        'code' => 'CUS-0926-000900',
        'first_name' => 'Booking',
        'last_name' => 'Customer',
        'mobile_number' => '09171111111',
        'mobile_normalized' => '09171111111',
        'birthday' => '1992-02-02',
        'registration_date' => '2026-09-12',
        'status' => 'ACTIVE',
    ]);

    return compact('user', 'service', 'personnel', 'customer');
}

it('creates one reservation booking with service snapshots', function () {
    $fx = bookingFixture();
    $payload = [
        'customer_id' => $fx['customer']->id,
        'customer_type' => 'NEW',
        'booking_date' => '2026-09-15',
        'booking_time' => '10:30',
        'booking_source' => 'RESERVATION',
        'services' => [[
            'service_id' => $fx['service']->id,
            'quantity' => 1,
            'assigned_personnel_id' => $fx['personnel']->id,
        ]],
        'retail_items' => [],
    ];

    $this->actingAs($fx['user'])->postJson('/api/v1/operations/bookings', $payload)
        ->assertCreated()
        ->assertJsonPath('booking.code', 'BKG-0926-000001')
        ->assertJsonPath('booking.status', 'BOOKED')
        ->assertJsonPath('booking.services.0.service_name', 'Test Haircut');

    expect(Booking::count())->toBe(1)
        ->and(Booking::first()->serviceLines()->count())->toBe(1);
});

it('rebooks append only history and preserves the booking identity', function () {
    $fx = bookingFixture();
    $booking = Booking::query()->create([
        'business_id' => $fx['user']->business_id,
        'branch_id' => $fx['user']->default_branch_id,
        'code' => 'BKG-0926-000900',
        'customer_id' => $fx['customer']->id,
        'customer_type' => 'NEW',
        'booking_date' => '2026-09-15',
        'booking_time' => '10:30:00',
        'booking_source' => 'RESERVATION',
        'status' => 'BOOKED',
    ]);

    $this->actingAs($fx['user'])->postJson("/api/v1/operations/bookings/{$booking->id}/rebook", [
        'booking_date' => '2026-09-17',
        'booking_time' => '14:00',
        'reason' => 'Customer requested a new schedule.',
    ])->assertOk()->assertJsonPath('booking.code', 'BKG-0926-000900');

    expect($booking->fresh()->booking_date->format('Y-m-d'))->toBe('2026-09-17')
        ->and($booking->rebookings()->count())->toBe(1);
});

it('does not allow walk in creation before queue phase', function () {
    $fx = bookingFixture();
    $this->actingAs($fx['user'])->postJson('/api/v1/operations/bookings', [
        'customer_id' => $fx['customer']->id,
        'customer_type' => 'NEW',
        'booking_date' => '2026-09-15',
        'booking_time' => '10:30',
        'booking_source' => 'WALK_IN',
        'services' => [['service_id' => $fx['service']->id]],
        'retail_items' => [],
    ])->assertStatus(422);
});

it('supports completed booking history as a separate view', function () {
    $fx = bookingFixture();

    Booking::query()->create([
        'business_id' => $fx['user']->business_id,
        'branch_id' => $fx['user']->default_branch_id,
        'code' => 'BKG-0926-000901',
        'customer_id' => $fx['customer']->id,
        'customer_type' => 'NEW',
        'booking_date' => '2026-09-15',
        'booking_time' => '10:30:00',
        'booking_source' => 'RESERVATION',
        'status' => 'COMPLETED',
    ]);

    Booking::query()->create([
        'business_id' => $fx['user']->business_id,
        'branch_id' => $fx['user']->default_branch_id,
        'code' => 'BKG-0926-000902',
        'customer_id' => $fx['customer']->id,
        'customer_type' => 'NEW',
        'booking_date' => '2026-09-16',
        'booking_time' => '11:00:00',
        'booking_source' => 'RESERVATION',
        'status' => 'BOOKED',
    ]);

    $response = $this->actingAs($fx['user'])->getJson('/api/v1/operations/bookings?view=COMPLETED');

    $response->assertOk()
        ->assertJsonCount(1, 'bookings')
        ->assertJsonPath('bookings.0.code', 'BKG-0926-000901')
        ->assertJsonPath('bookings.0.status', 'COMPLETED');
});
