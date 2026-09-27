<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Personnel;
use App\Models\QueueEntry;
use App\Models\ReferenceGroup;
use App\Models\ReferenceValue;
use App\Models\Sale;
use App\Models\Service;
use App\Models\Visit;
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

it('filters bookings by customer service source and payment status', function () {
    $fx = bookingFixture();

    $this->actingAs($fx['user'])->postJson('/api/v1/operations/bookings', [
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
    ])->assertCreated();

    $this->actingAs($fx['user'])->getJson('/api/v1/operations/bookings?view=ACTIVE&customer_name=Booking%20Customer&service_id='.$fx['service']->id.'&source=RESERVATION&payment=UNPAID')
        ->assertOk()
        ->assertJsonCount(1, 'bookings')
        ->assertJsonPath('bookings.0.customer.name', 'Booking Customer')
        ->assertJsonPath('bookings.0.payment_status', 'UNPAID')
        ->assertJsonPath('bookings.0.payment_type', 'UNPAID')
        ->assertJsonPath('bookings.0.gross_collected_total', 0);
});


it('shows only the amount actually applied to a completed booking after discounts', function () {
    $fx = bookingFixture();

    $booking = Booking::query()->create([
        'business_id' => $fx['user']->business_id,
        'branch_id' => $fx['user']->default_branch_id,
        'code' => 'BKG-0926-000950',
        'customer_id' => $fx['customer']->id,
        'customer_type' => 'RETURNEE',
        'booking_date' => '2026-09-18',
        'booking_time' => '10:30:00',
        'booking_source' => 'RESERVATION',
        'status' => 'COMPLETED',
    ]);

    $queue = QueueEntry::query()->create([
        'business_id' => $fx['user']->business_id,
        'branch_id' => $fx['user']->default_branch_id,
        'code' => 'QUE-0926-000950',
        'booking_id' => $booking->id,
        'customer_id' => $fx['customer']->id,
        'customer_type' => 'RETURNEE',
        'queue_date' => '2026-09-18',
        'check_in_at' => '2026-09-18 10:20:00',
        'source' => 'RESERVATION',
        'status' => 'COMPLETED',
    ]);

    $visit = Visit::query()->create([
        'business_id' => $fx['user']->business_id,
        'branch_id' => $fx['user']->default_branch_id,
        'code' => 'VIS-0926-000950',
        'queue_entry_id' => $queue->id,
        'booking_id' => $booking->id,
        'customer_id' => $fx['customer']->id,
        'customer_type' => 'RETURNEE',
        'visit_date' => '2026-09-18',
        'check_in_at' => '2026-09-18 10:20:00',
        'service_start_at' => '2026-09-18 10:30:00',
        'check_out_at' => '2026-09-18 11:30:00',
        'status' => 'COMPLETED',
    ]);

    Sale::query()->create([
        'business_id' => $fx['user']->business_id,
        'branch_id' => $fx['user']->default_branch_id,
        'code' => 'SAL-0926-000950',
        'visit_id' => $visit->id,
        'booking_id' => $booking->id,
        'customer_id' => $fx['customer']->id,
        'sale_date' => '2026-09-18 11:30:00',
        'gross_amount' => 1000,
        'discount_amount' => 200,
        'net_amount' => 800,
        'advance_payments' => 0,
        'checkout_payments' => 300,
        'total_paid' => 300,
        'applied_amount' => 300,
        'outstanding_balance' => 500,
        'status' => 'ON_ACCOUNT',
        'financial_finalized_at' => '2026-09-18 11:35:00',
        'created_by' => $fx['user']->id,
        'updated_by' => $fx['user']->id,
    ]);

    $this->actingAs($fx['user'])
        ->getJson('/api/v1/operations/bookings?view=COMPLETED&search=BKG-0926-000950')
        ->assertOk()
        ->assertJsonCount(1, 'bookings')
        ->assertJsonPath('bookings.0.net_after_discount_total', 800)
        ->assertJsonPath('bookings.0.collected_received_total', 300)
        ->assertJsonPath('bookings.0.payment_status', 'ON_ACCOUNT');
});

it('uses the bookings search field for booking id only', function () {
    $fx = bookingFixture();

    Booking::query()->create([
        'business_id' => $fx['user']->business_id,
        'branch_id' => $fx['user']->default_branch_id,
        'code' => 'BKG-0926-000960',
        'customer_id' => $fx['customer']->id,
        'customer_type' => 'NEW',
        'booking_date' => '2026-09-18',
        'booking_time' => '09:00:00',
        'booking_source' => 'RESERVATION',
        'status' => 'BOOKED',
    ]);

    $this->actingAs($fx['user'])
        ->getJson('/api/v1/operations/bookings?view=ACTIVE&search=09171111111')
        ->assertOk()
        ->assertJsonCount(0, 'bookings');

    $this->actingAs($fx['user'])
        ->getJson('/api/v1/operations/bookings?view=ACTIVE&search=BKG-0926-000960')
        ->assertOk()
        ->assertJsonCount(1, 'bookings')
        ->assertJsonPath('bookings.0.code', 'BKG-0926-000960');
});


it('requires payment proof for a non-cash reservation payment while cash is exempt', function () {
    $fx = bookingFixture();
    $group = ReferenceGroup::query()->where('business_id', $fx['user']->business_id)->where('code', 'PAYMENT_METHOD')->firstOrFail();
    $cash = ReferenceValue::query()->create([
        'reference_group_id' => $group->id,
        'code' => 'CASH-BOOKING-PROOF',
        'display_value' => 'Cash',
        'sort_order' => 10,
        'active' => true,
    ]);
    $gcash = ReferenceValue::query()->create([
        'reference_group_id' => $group->id,
        'code' => 'GCASH-BOOKING-PROOF',
        'display_value' => 'GCash',
        'sort_order' => 20,
        'active' => true,
    ]);
    mapCustomerPaymentMethod($fx['user'], $cash);
    mapCustomerPaymentMethod($fx['user'], $gcash, 'POS_CLEARING');

    $base = [
        'customer_id' => $fx['customer']->id,
        'customer_type' => 'NEW',
        'booking_date' => '2026-09-20',
        'booking_time' => '10:30',
        'booking_source' => 'RESERVATION',
        'services' => [[
            'service_id' => $fx['service']->id,
            'quantity' => 1,
            'assigned_personnel_id' => $fx['personnel']->id,
        ]],
        'retail_items' => [],
    ];

    $this->actingAs($fx['user'])->postJson('/api/v1/operations/bookings', $base + [
        'payment_collection' => [
            'collected' => true,
            'amount' => 100,
            'advance_amount' => 100,
            'payment_method_reference_id' => $gcash->id,
            'allocations' => [],
        ],
    ])->assertStatus(422)
        ->assertJsonPath('message', 'Booking payment proof is required for non-cash payments such as GCash, bank transfer, e-wallet or card. Cash payments are exempt.');

    expect(Booking::count())->toBe(0);

    $proofToken = $this->supportingDocumentToken($fx['user'], 'bookings', 'reservation-gcash-proof.pdf');
    $created = $this->actingAs($fx['user'])->postJson('/api/v1/operations/bookings', $base + [
        'payment_collection' => [
            'collected' => true,
            'amount' => 100,
            'advance_amount' => 100,
            'payment_method_reference_id' => $gcash->id,
            'allocations' => [],
            'attachment_tokens' => [$proofToken],
        ],
    ])->assertCreated();

    $this->actingAs($fx['user'])
        ->getJson('/api/v1/supporting-documents?entity_type=BOOKING_PAYMENT&entity_id='.$created->json('booking.id'))
        ->assertOk()
        ->assertJsonCount(1, 'documents')
        ->assertJsonPath('documents.0.name', 'reservation-gcash-proof.pdf');

    $this->actingAs($fx['user'])->postJson('/api/v1/operations/bookings', array_replace_recursive($base, [
        'booking_date' => '2026-09-21',
        'payment_collection' => [
            'collected' => true,
            'amount' => 100,
            'advance_amount' => 100,
            'payment_method_reference_id' => $cash->id,
            'allocations' => [],
        ],
    ]))->assertCreated();
});
