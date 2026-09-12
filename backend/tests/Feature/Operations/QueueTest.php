<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Personnel;
use App\Models\QueueEntry;
use App\Models\ReferenceGroup;
use App\Models\Role;
use App\Models\Service;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\OperationsPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    $this->seed(FoundationSeeder::class);
    $this->seed(MasterDataSeeder::class);
    $this->seed(OperationsPermissionSeeder::class);
});

function queueFixture(): array
{
    $user = foundationUser();
    $groups = ReferenceGroup::query()->with('values')->get()->keyBy('code');
    $service = Service::query()->create([
        'business_id' => $user->business_id, 'code' => 'SRV-Q-001', 'name' => 'Queue Haircut',
        'service_category_reference_id' => $groups['SERVICE_CATEGORY']->values->firstWhere('code', 'HAIRCUT')->id,
        'service_count_reference_id' => $groups['SERVICE_COUNT']->values->firstWhere('code', 'SINGLE_SESSION')->id,
        'number_of_sessions' => 1, 'duration_minutes' => 45,
        'status_reference_id' => $groups['SERVICE_STATUS']->values->firstWhere('code', 'ACTIVE')->id,
    ]);
    $personnel = Personnel::query()->create([
        'business_id' => $user->business_id, 'code' => 'EMP-Q-001', 'first_name' => 'Queue', 'last_name' => 'Barber',
        'position_reference_id' => $groups['EMPLOYEE_POSITION']->values->firstWhere('code', 'BARBER')->id,
        'personnel_type_reference_id' => $groups['PERSONNEL_TYPE']->values->firstWhere('code', 'EMPLOYEE')->id,
        'compensation_rule_reference_id' => $groups['COMPENSATION_RULE']->values->firstWhere('code', 'HOURLY_PLUS_COMMISSION')->id,
        'hourly_rate' => 100, 'can_perform_services' => true, 'commission_eligible' => true, 'status' => 'ACTIVE',
    ]);
    $customer = Customer::query()->create([
        'business_id' => $user->business_id, 'code' => 'CUS-Q-001', 'first_name' => 'Queue', 'last_name' => 'Customer',
        'mobile_number' => '09172222222', 'mobile_normalized' => '09172222222', 'birthday' => '1991-01-01',
        'registration_date' => '2026-09-12', 'status' => 'ACTIVE',
    ]);
    return compact('user', 'service', 'personnel', 'customer');
}

it('checks in a reservation once and snapshots booking lines into queue', function () {
    $fx = queueFixture();
    $create = $this->actingAs($fx['user'])->postJson('/api/v1/operations/bookings', [
        'customer_id' => $fx['customer']->id, 'customer_type' => 'NEW', 'booking_date' => '2026-09-15',
        'booking_time' => '10:30', 'booking_source' => 'RESERVATION',
        'services' => [['service_id' => $fx['service']->id, 'quantity' => 1, 'assigned_personnel_id' => $fx['personnel']->id]],
        'retail_items' => [],
    ])->assertCreated();

    $bookingId = $create->json('booking.id');
    $first = $this->actingAs($fx['user'])->postJson("/api/v1/operations/bookings/{$bookingId}/check-in")
        ->assertOk()->assertJsonPath('queue_entry.source', 'RESERVATION')->assertJsonPath('queue_entry.status', 'WAITING');
    $queueCode = $first->json('queue_entry.code');

    $this->actingAs($fx['user'])->postJson("/api/v1/operations/bookings/{$bookingId}/check-in")
        ->assertOk()->assertJsonPath('queue_entry.code', $queueCode);

    expect(QueueEntry::count())->toBe(1)
        ->and(Booking::find($bookingId)->status)->toBe('CHECKED_IN')
        ->and(QueueEntry::first()->serviceLines()->count())->toBe(1)
        ->and(QueueEntry::first()->serviceLines()->first()->assigned_personnel_name_snapshot)->toBe('Queue Barber');
});

it('creates a walk in booking and queue atomically', function () {
    $fx = queueFixture();
    $this->actingAs($fx['user'])->postJson('/api/v1/operations/queue/walk-ins', [
        'customer_id' => $fx['customer']->id, 'customer_type' => 'RETURNEE',
        'services' => [['service_id' => $fx['service']->id, 'quantity' => 1, 'assigned_personnel_id' => $fx['personnel']->id]],
        'retail_items' => [], 'notes' => 'Walk-in test',
    ])->assertCreated()->assertJsonPath('queue_entry.source', 'WALK_IN')->assertJsonPath('queue_entry.booking.status', 'CHECKED_IN');

    expect(Booking::count())->toBe(1)->and(QueueEntry::count())->toBe(1)
        ->and(Booking::first()->booking_source)->toBe('WALK_IN')
        ->and(Booking::first()->code)->toStartWith('BKG-')
        ->and(QueueEntry::first()->code)->toStartWith('QUE-');
});

it('cancels only waiting queue and preserves the booking history', function () {
    $fx = queueFixture();
    $response = $this->actingAs($fx['user'])->postJson('/api/v1/operations/queue/walk-ins', [
        'customer_id' => $fx['customer']->id, 'customer_type' => 'NEW',
        'services' => [['service_id' => $fx['service']->id]], 'retail_items' => [],
    ])->assertCreated();
    $queueId = $response->json('queue_entry.id');

    $this->actingAs($fx['user'])->postJson("/api/v1/operations/queue/{$queueId}/cancel", ['reason' => 'Customer left before service.'])
        ->assertOk()->assertJsonPath('queue_entry.status', 'CANCELLED');

    $queue = QueueEntry::find($queueId);
    expect($queue->cancellation_reason)->toBe('Customer left before service.')
        ->and($queue->booking->status)->toBe('CANCELLED')
        ->and(Booking::count())->toBe(1);
});

it('keeps legacy queue priority between reservations and physical walk ins', function () {
    $fx = queueFixture();
    $branch = $fx['user']->default_branch_id;
    $makeBooking = function (string $code, string $createdAt) use ($fx, $branch) {
        $b = Booking::query()->create([
            'business_id' => $fx['user']->business_id, 'branch_id' => $branch, 'code' => $code,
            'customer_id' => $fx['customer']->id, 'customer_type' => 'NEW', 'booking_date' => '2026-09-12',
            'booking_time' => '12:00:00', 'booking_source' => str_contains($code, 'W') ? 'WALK_IN' : 'RESERVATION', 'status' => 'CHECKED_IN',
        ]);
        $b->timestamps = false; $b->created_at = $createdAt; $b->updated_at = $createdAt; $b->save();
        return $b;
    };
    $resA = $makeBooking('BKG-R-A', '2026-09-12 08:00:00');
    $walk1 = $makeBooking('BKG-W-1', '2026-09-12 09:00:00');
    $resB = $makeBooking('BKG-R-B', '2026-09-12 09:30:00');
    $walk2 = $makeBooking('BKG-W-2', '2026-09-12 10:00:00');

    foreach ([
        ['QUE-A', $resA, 'RESERVATION', '09:45:00', '2026-09-12 08:00:00'],
        ['QUE-W1', $walk1, 'WALK_IN', '09:00:00', '2026-09-12 09:00:00'],
        ['QUE-B', $resB, 'RESERVATION', '09:50:00', '2026-09-12 09:30:00'],
        ['QUE-W2', $walk2, 'WALK_IN', '10:00:00', '2026-09-12 10:00:00'],
    ] as [$code, $booking, $source, $check, $created]) {
        QueueEntry::query()->create([
            'business_id' => $fx['user']->business_id, 'branch_id' => $branch, 'code' => $code,
            'booking_id' => $booking->id, 'customer_id' => $fx['customer']->id, 'customer_type' => 'NEW',
            'queue_date' => '2026-09-12', 'check_in_at' => '2026-09-12 '.$check, 'source' => $source, 'status' => 'WAITING',
            'booking_created_at' => $created, 'scheduled_date' => $source === 'RESERVATION' ? '2026-09-12' : null,
            'scheduled_time' => $source === 'RESERVATION' ? '12:00:00' : null,
        ]);
    }

    $response = $this->actingAs($fx['user'])->getJson('/api/v1/operations/queue?date=2026-09-12&status=WAITING')->assertOk();
    expect($response->json('queue.*.code'))->toBe(['QUE-A', 'QUE-W1', 'QUE-B', 'QUE-W2']);
});

it('ensures the reception walk in permissions without replacing other role permissions', function () {
    $role = Role::query()->where('code', 'ROLE-REC')->firstOrFail();
    $codes = $role->permissions()->pluck('code')->all();
    expect($codes)->toContain('queue.CREATE')->and($codes)->toContain('bookings.CREATE')->and($codes)->toContain('queue.VIEW');
});


it('does not strip existing reception permissions when foundation seeders are rerun', function () {
    $role = Role::query()->where('code', 'ROLE-REC')->firstOrFail();
    $edit = \App\Models\Permission::query()->where('code', 'queue.EDIT')->firstOrFail();
    $role->permissions()->syncWithoutDetaching([$edit->id]);

    $this->seed(FoundationSeeder::class);
    $this->seed(OperationsPermissionSeeder::class);

    $codes = $role->fresh()->permissions()->pluck('code')->all();
    expect($codes)->toContain('queue.EDIT')->and($codes)->toContain('queue.CREATE')->and($codes)->toContain('bookings.CREATE');
});
