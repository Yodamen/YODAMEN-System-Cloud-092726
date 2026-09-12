<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Personnel;
use App\Models\QueueEntry;
use App\Models\ReferenceGroup;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceCompletionEvent;
use App\Models\Visit;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\OperationsPermissionSeeder;
use Database\Seeders\ServicingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    $this->seed(FoundationSeeder::class);
    $this->seed(MasterDataSeeder::class);
    $this->seed(OperationsPermissionSeeder::class);
    $this->seed(ServicingPermissionSeeder::class);
});

function servicingFixture(): array
{
    $user = foundationUser();
    $groups = ReferenceGroup::query()->with('values')->get()->keyBy('code');

    $service = Service::query()->create([
        'business_id' => $user->business_id,
        'code' => 'SRV-V-001',
        'name' => 'Visit Haircut',
        'service_category_reference_id' => $groups['SERVICE_CATEGORY']->values->firstWhere('code', 'HAIRCUT')->id,
        'service_count_reference_id' => $groups['SERVICE_COUNT']->values->firstWhere('code', 'SINGLE_SESSION')->id,
        'number_of_sessions' => 1,
        'duration_minutes' => 45,
        'status_reference_id' => $groups['SERVICE_STATUS']->values->firstWhere('code', 'ACTIVE')->id,
    ]);

    $service2 = Service::query()->create([
        'business_id' => $user->business_id,
        'code' => 'SRV-V-002',
        'name' => 'Visit Shave',
        'service_category_reference_id' => $groups['SERVICE_CATEGORY']->values->firstWhere('code', 'HAIRCUT')->id,
        'service_count_reference_id' => $groups['SERVICE_COUNT']->values->firstWhere('code', 'SINGLE_SESSION')->id,
        'number_of_sessions' => 1,
        'duration_minutes' => 30,
        'status_reference_id' => $groups['SERVICE_STATUS']->values->firstWhere('code', 'ACTIVE')->id,
    ]);

    $personnel = Personnel::query()->create([
        'business_id' => $user->business_id,
        'code' => 'EMP-V-001',
        'first_name' => 'Visit',
        'last_name' => 'Barber One',
        'position_reference_id' => $groups['EMPLOYEE_POSITION']->values->firstWhere('code', 'BARBER')->id,
        'personnel_type_reference_id' => $groups['PERSONNEL_TYPE']->values->firstWhere('code', 'EMPLOYEE')->id,
        'compensation_rule_reference_id' => $groups['COMPENSATION_RULE']->values->firstWhere('code', 'HOURLY_PLUS_COMMISSION')->id,
        'hourly_rate' => 100,
        'can_perform_services' => true,
        'commission_eligible' => true,
        'status' => 'ACTIVE',
    ]);

    $personnel2 = Personnel::query()->create([
        'business_id' => $user->business_id,
        'code' => 'EMP-V-002',
        'first_name' => 'Visit',
        'last_name' => 'Barber Two',
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
        'code' => 'CUS-V-001',
        'first_name' => 'Visit',
        'last_name' => 'Customer',
        'mobile_number' => '09173333333',
        'mobile_normalized' => '09173333333',
        'birthday' => '1990-05-10',
        'registration_date' => '2026-09-12',
        'status' => 'ACTIVE',
    ]);

    return compact('user', 'service', 'service2', 'personnel', 'personnel2', 'customer');
}

function createStartedVisit($test, array $fx, bool $twoServices = false): Visit
{
    $services = [[
        'service_id' => $fx['service']->id,
        'quantity' => 1,
        'assigned_personnel_id' => $fx['personnel']->id,
    ]];

    if ($twoServices) {
        $services[] = [
            'service_id' => $fx['service2']->id,
            'quantity' => 1,
            'assigned_personnel_id' => $fx['personnel2']->id,
        ];
    }

    $queueResponse = $test->actingAs($fx['user'])->postJson('/api/v1/operations/queue/walk-ins', [
        'customer_id' => $fx['customer']->id,
        'customer_type' => 'NEW',
        'services' => $services,
        'retail_items' => [],
    ])->assertCreated();

    $queueId = $queueResponse->json('queue_entry.id');

    $visitResponse = $test->actingAs($fx['user'])
        ->postJson("/api/v1/operations/queue/{$queueId}/start-servicing")
        ->assertOk()
        ->assertJsonPath('visit.status', 'IN_SERVICE');

    return Visit::query()->findOrFail($visitResponse->json('visit.id'));
}

it('creates exactly one visit when servicing starts and updates queue and booking lifecycle', function () {
    $fx = servicingFixture();
    $visit = createStartedVisit($this, $fx);

    $queue = $visit->queueEntry;
    $booking = $visit->booking;

    $this->actingAs($fx['user'])->postJson("/api/v1/operations/queue/{$queue->id}/start-servicing")
        ->assertOk()->assertJsonPath('visit.code', $visit->code);

    expect(Visit::count())->toBe(1)
        ->and($visit->code)->toStartWith('VIS-')
        ->and($visit->serviceLines()->count())->toBe(1)
        ->and($queue->fresh()->status)->toBe('SERVICING')
        ->and($booking->fresh()->status)->toBe('ON_GOING');
});

it('preserves separate staff assignments for multi service visits', function () {
    $fx = servicingFixture();
    $visit = createStartedVisit($this, $fx, true)->fresh('serviceLines');

    expect($visit->serviceLines)->toHaveCount(2)
        ->and($visit->serviceLines[0]->assigned_personnel_id)->toBe($fx['personnel']->id)
        ->and($visit->serviceLines[1]->assigned_personnel_id)->toBe($fx['personnel2']->id)
        ->and($visit->serviceLines[0]->status)->toBe('IN_PROGRESS')
        ->and($visit->serviceLines[1]->status)->toBe('IN_PROGRESS');
});

it('records one append only completion event and keeps visit open while another service remains active', function () {
    $fx = servicingFixture();
    $visit = createStartedVisit($this, $fx, true)->fresh('serviceLines');
    $line = $visit->serviceLines[0];

    $this->actingAs($fx['user'])->postJson("/api/v1/operations/visit-services/{$line->id}/complete")
        ->assertOk()->assertJsonPath('visit.status', 'IN_SERVICE');
    $this->actingAs($fx['user'])->postJson("/api/v1/operations/visit-services/{$line->id}/complete")
        ->assertOk()->assertJsonPath('visit.status', 'IN_SERVICE');

    expect(ServiceCompletionEvent::count())->toBe(1)
        ->and($line->fresh()->status)->toBe('COMPLETED')
        ->and($visit->fresh()->status)->toBe('IN_SERVICE');
});

it('completes visit booking and queue after the last active service completes', function () {
    $fx = servicingFixture();
    $visit = createStartedVisit($this, $fx, true)->fresh('serviceLines');

    foreach ($visit->serviceLines as $line) {
        $this->actingAs($fx['user'])->postJson("/api/v1/operations/visit-services/{$line->id}/complete")->assertOk();
    }

    expect($visit->fresh()->status)->toBe('COMPLETED')
        ->and($visit->booking->fresh()->status)->toBe('COMPLETED')
        ->and($visit->queueEntry->fresh()->status)->toBe('COMPLETED')
        ->and(ServiceCompletionEvent::count())->toBe(2);
});

it('treats a mixed completed and cancelled visit as operationally completed', function () {
    $fx = servicingFixture();
    $visit = createStartedVisit($this, $fx, true)->fresh('serviceLines');

    $this->actingAs($fx['user'])->postJson("/api/v1/operations/visit-services/{$visit->serviceLines[0]->id}/complete")->assertOk();
    $this->actingAs($fx['user'])->postJson("/api/v1/operations/visit-services/{$visit->serviceLines[1]->id}/cancel", [
        'reason' => 'Customer declined second service.',
    ])->assertOk()->assertJsonPath('visit.status', 'COMPLETED');

    expect($visit->fresh()->status)->toBe('COMPLETED')
        ->and($visit->booking->fresh()->status)->toBe('COMPLETED')
        ->and(ServiceCompletionEvent::count())->toBe(1);
});

it('cancels visit booking and queue when every service is cancelled', function () {
    $fx = servicingFixture();
    $visit = createStartedVisit($this, $fx, true)->fresh('serviceLines');

    foreach ($visit->serviceLines as $line) {
        $this->actingAs($fx['user'])->postJson("/api/v1/operations/visit-services/{$line->id}/cancel", [
            'reason' => 'Customer cancelled service.',
        ])->assertOk();
    }

    expect($visit->fresh()->status)->toBe('CANCELLED')
        ->and($visit->booking->fresh()->status)->toBe('CANCELLED')
        ->and($visit->queueEntry->fresh()->status)->toBe('CANCELLED')
        ->and(ServiceCompletionEvent::count())->toBe(0);
});

it('allows staff reassignment before completion and freezes assignment after completion', function () {
    $fx = servicingFixture();
    $visit = createStartedVisit($this, $fx)->fresh('serviceLines');
    $line = $visit->serviceLines[0];

    $this->actingAs($fx['user'])->patchJson("/api/v1/operations/visit-services/{$line->id}/staff", [
        'assigned_personnel_id' => $fx['personnel2']->id,
    ])->assertOk()->assertJsonPath('visit.services.0.assigned_personnel_id', $fx['personnel2']->id);

    $this->actingAs($fx['user'])->postJson("/api/v1/operations/visit-services/{$line->id}/complete")->assertOk();

    $this->actingAs($fx['user'])->patchJson("/api/v1/operations/visit-services/{$line->id}/staff", [
        'assigned_personnel_id' => $fx['personnel']->id,
    ])->assertStatus(422);
});

it('can add a new service to an active visit without changing historical queue snapshots', function () {
    $fx = servicingFixture();
    $visit = createStartedVisit($this, $fx);
    $queueServiceCount = $visit->queueEntry->serviceLines()->count();

    $this->actingAs($fx['user'])->postJson("/api/v1/operations/servicing/{$visit->id}/services", [
        'service_id' => $fx['service2']->id,
        'quantity' => 1,
        'assigned_personnel_id' => $fx['personnel2']->id,
    ])->assertOk()->assertJsonCount(2, 'visit.services');

    expect($visit->fresh()->serviceLines()->count())->toBe(2)
        ->and($visit->queueEntry->serviceLines()->count())->toBe($queueServiceCount);
});

it('restores servicing view access for legacy manager reception and barber roles without adding action permissions', function () {
    foreach (['ROLE-MGR', 'ROLE-REC', 'ROLE-BAR'] as $roleCode) {
        $role = Role::query()->where('code', $roleCode)->firstOrFail();
        $codes = $role->permissions()->pluck('code')->all();
        expect($codes)->toContain('servicing.VIEW');
    }

    $reception = Role::query()->where('code', 'ROLE-REC')->firstOrFail();
    expect($reception->permissions()->pluck('code')->all())->not->toContain('servicing.COMPLETE');
});
