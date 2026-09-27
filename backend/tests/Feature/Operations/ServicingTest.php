<?php

use App\Models\Booking;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\RetailProduct;
use App\Models\ServiceConsumption;
use App\Models\ServiceConsumptionRecipe;
use App\Models\Customer;
use App\Models\Personnel;
use App\Models\PackageEnrollment;
use App\Models\QueueEntry;
use App\Models\ReferenceGroup;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceCompletionEvent;
use App\Models\ServiceCredit;
use App\Models\Sale;
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

function servicingRetailProduct(array $fx, string $name, string $sku, float $sellingPrice, float $onHand = 20): RetailProduct
{
    $product = RetailProduct::query()->create([
        'business_id' => $fx['user']->business_id,
        'sku' => $sku,
        'name' => $name,
        'category' => 'Retail',
        'unit' => 'pc',
        'purchase_uom' => 'pc',
        'purchase_to_basic_factor' => 1,
        'stock_type' => 'INVENTORY',
        'retail_available' => true,
        'unit_cost' => 50,
        'selling_price' => $sellingPrice,
        'reorder_point' => 2,
        'commission_eligible' => true,
        'status' => 'ACTIVE',
        'created_by' => $fx['user']->id,
        'updated_by' => $fx['user']->id,
    ]);

    InventoryStock::query()->create([
        'business_id' => $fx['user']->business_id,
        'branch_id' => $fx['user']->default_branch_id,
        'retail_product_id' => $product->id,
        'on_hand' => $onHand,
        'reserved' => 0,
    ]);

    return $product;
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

it('lets completion update assigned staff and retail purchases before the visit is finalized', function () {
    $fx = servicingFixture();
    $visit = createStartedVisit($this, $fx)->fresh('serviceLines');
    $line = $visit->serviceLines->first();
    $product = servicingRetailProduct($fx, 'Styling Wax', 'SKU-SERVICING-WAX-001', 175);

    $this->actingAs($fx['user'])->postJson("/api/v1/operations/visit-services/{$line->id}/complete", [
        'assigned_personnel_id' => $fx['personnel2']->id,
        'service_credit_id' => null,
        'retail_items' => [[
            'retail_product_id' => $product->id,
            'item_name' => 'Styling Wax',
            'quantity' => 2,
            'unit_price' => 175,
            'notes' => 'Added during completion',
            'assigned_personnel_id' => $fx['personnel2']->id,
        ]],
    ])->assertOk()
        ->assertJsonPath('visit.status', 'COMPLETED')
        ->assertJsonPath('visit.services.0.assigned_personnel_id', $fx['personnel2']->id)
        ->assertJsonPath('visit.retail_items.0.item_name', 'Styling Wax')
        ->assertJsonPath('visit.retail_items.0.quantity', 2);

    $event = ServiceCompletionEvent::query()->where('visit_service_line_id', $line->id)->firstOrFail();
    $sale = Sale::query()->where('visit_id', $visit->id)->firstOrFail()->fresh('items');

    expect($line->fresh()->assigned_personnel_id)->toBe($fx['personnel2']->id)
        ->and($event->assigned_personnel_id)->toBe($fx['personnel2']->id)
        ->and($visit->retailLines()->count())->toBe(1)
        ->and($sale->items->where('item_type', 'PRODUCT')->first()->item_name_snapshot)->toBe('Styling Wax')
        ->and((float) $sale->items->where('item_type', 'PRODUCT')->first()->line_total)->toBe(350.0);
});

it('lets a regular service claim an available Service Credit during completion', function () {
    $fx = servicingFixture();
    $visit = createStartedVisit($this, $fx)->fresh('serviceLines');
    $line = $visit->serviceLines->first();

    $enrollment = PackageEnrollment::query()->create([
        'business_id' => $fx['user']->business_id,
        'code' => 'PKE-COMPLETE-001',
        'customer_id' => $fx['customer']->id,
        'service_id' => $fx['service']->id,
        'service_name_snapshot' => $fx['service']->name,
        'total_sessions' => 3,
        'package_price_snapshot' => 900,
        'status' => 'ACTIVE',
        'sessions_completed_before_activation' => 0,
        'activated_at' => now(),
    ]);

    $credit = ServiceCredit::query()->create([
        'business_id' => $fx['user']->business_id,
        'code' => 'SCR-COMPLETE-001',
        'package_enrollment_id' => $enrollment->id,
        'customer_id' => $fx['customer']->id,
        'service_id' => $fx['service']->id,
        'service_name_snapshot' => $fx['service']->name,
        'sessions_purchased' => 3,
        'sessions_used' => 0,
        'sessions_remaining' => 3,
        'status' => 'ACTIVE',
    ]);

    $this->actingAs($fx['user'])->postJson("/api/v1/operations/visit-services/{$line->id}/complete", [
        'assigned_personnel_id' => $fx['personnel']->id,
        'service_credit_id' => $credit->id,
        'retail_items' => [],
    ])->assertOk()
        ->assertJsonPath('visit.services.0.source_type', 'SERVICE_CREDIT')
        ->assertJsonPath('visit.services.0.service_credit_id', $credit->id)
        ->assertJsonPath('visit.status', 'COMPLETED');

    $credit->refresh();
    $sale = Sale::query()->where('visit_id', $visit->id)->firstOrFail()->fresh('items');
    $serviceItem = $sale->items->firstWhere('visit_service_line_id', $line->id);

    expect($line->fresh()->service_credit_id)->toBe($credit->id)
        ->and($credit->sessions_used)->toBe(1)
        ->and($credit->sessions_remaining)->toBe(2)
        ->and($serviceItem->item_type)->toBe('SERVICE_CREDIT_USE')
        ->and((float) $serviceItem->line_total)->toBe(0.0);
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

it('lets Manage claim a Service Credit with assigned staff before service completion', function () {
    $fx = servicingFixture();
    $visit = createStartedVisit($this, $fx)->fresh('serviceLines');

    $enrollment = PackageEnrollment::query()->create([
        'business_id' => $fx['user']->business_id,
        'code' => 'PKE-MANAGE-001',
        'customer_id' => $fx['customer']->id,
        'service_id' => $fx['service2']->id,
        'service_name_snapshot' => $fx['service2']->name,
        'total_sessions' => 3,
        'package_price_snapshot' => 900,
        'status' => 'ACTIVE',
        'sessions_completed_before_activation' => 0,
        'activated_at' => now(),
    ]);

    $credit = ServiceCredit::query()->create([
        'business_id' => $fx['user']->business_id,
        'code' => 'SCR-MANAGE-001',
        'package_enrollment_id' => $enrollment->id,
        'customer_id' => $fx['customer']->id,
        'service_id' => $fx['service2']->id,
        'service_name_snapshot' => $fx['service2']->name,
        'sessions_purchased' => 3,
        'sessions_used' => 0,
        'sessions_remaining' => 3,
        'status' => 'ACTIVE',
    ]);

    $response = $this->actingAs($fx['user'])->postJson("/api/v1/operations/servicing/{$visit->id}/services", [
        'service_id' => $fx['service2']->id,
        'service_credit_id' => $credit->id,
        'quantity' => 1,
        'assigned_personnel_id' => $fx['personnel2']->id,
    ])->assertOk()->assertJsonCount(2, 'visit.services');

    $creditLine = collect($response->json('visit.services'))->firstWhere('service_credit_id', $credit->id);

    expect($creditLine)->not->toBeNull()
        ->and($creditLine['source_type'])->toBe('SERVICE_CREDIT')
        ->and($creditLine['assigned_personnel_id'])->toBe($fx['personnel2']->id)
        ->and($credit->fresh()->sessions_remaining)->toBe(3);

    $this->actingAs($fx['user'])
        ->getJson("/api/v1/operations/customers/{$fx['customer']->id}/service-credits")
        ->assertOk()
        ->assertJsonPath('service_credits.0.available_sessions', 2);
});

it('lets Manage save Product Purchases before any service is completed', function () {
    $fx = servicingFixture();
    $visit = createStartedVisit($this, $fx);
    $product = servicingRetailProduct($fx, 'Pomade', 'SKU-SERVICING-POMADE-001', 220);

    $this->actingAs($fx['user'])->putJson("/api/v1/operations/servicing/{$visit->id}/retail-items", [
        'retail_items' => [[
            'retail_product_id' => $product->id,
            'item_name' => 'Pomade',
            'quantity' => 2,
            'unit_price' => 220,
            'notes' => 'Added from Manage',
            'assigned_personnel_id' => $fx['personnel']->id,
        ]],
    ])->assertOk()
        ->assertJsonPath('visit.status', 'IN_SERVICE')
        ->assertJsonPath('visit.retail_items.0.item_name', 'Pomade')
        ->assertJsonPath('visit.retail_items.0.quantity', 2);

    expect($visit->fresh()->retailLines()->count())->toBe(1)
        ->and($visit->fresh()->retailLines()->first()->item_name_snapshot)->toBe('Pomade')
        ->and(Sale::query()->where('visit_id', $visit->id)->exists())->toBeFalse();
});

it('completes multiple selected services from Manage in one request and keeps the operation atomic', function () {
    $fx = servicingFixture();
    $visit = createStartedVisit($this, $fx, true)->fresh('serviceLines');
    $serviceIds = $visit->serviceLines->pluck('id')->values()->all();
    $product = servicingRetailProduct($fx, 'Finishing Wax', 'SKU-SERVICING-FINISH-001', 180);

    $this->actingAs($fx['user'])->postJson("/api/v1/operations/servicing/{$visit->id}/complete-services", [
        'service_line_ids' => $serviceIds,
        'retail_items' => [[
            'retail_product_id' => $product->id,
            'item_name' => 'Finishing Wax',
            'quantity' => 1,
            'unit_price' => 180,
            'notes' => 'Added before batch completion',
            'assigned_personnel_id' => $fx['personnel']->id,
        ]],
    ])->assertOk()
        ->assertJsonPath('visit.status', 'COMPLETED')
        ->assertJsonCount(2, 'visit.services')
        ->assertJsonPath('visit.retail_items.0.item_name', 'Finishing Wax');

    expect($visit->fresh()->serviceLines()->where('status', 'COMPLETED')->count())->toBe(2)
        ->and(ServiceCompletionEvent::query()->where('visit_id', $visit->id)->count())->toBe(2)
        ->and($visit->fresh()->status)->toBe('COMPLETED')
        ->and($visit->retailLines()->count())->toBe(1);
});

it('completes only checked services and leaves the visit open when another service is still active', function () {
    $fx = servicingFixture();
    $visit = createStartedVisit($this, $fx, true)->fresh('serviceLines');
    $selected = $visit->serviceLines->first();
    $remaining = $visit->serviceLines->last();

    $this->actingAs($fx['user'])->postJson("/api/v1/operations/servicing/{$visit->id}/complete-services", [
        'service_line_ids' => [$selected->id],
        'retail_items' => [],
    ])->assertOk()
        ->assertJsonPath('visit.status', 'IN_SERVICE');

    expect($selected->fresh()->status)->toBe('COMPLETED')
        ->and($remaining->fresh()->status)->toBe('IN_PROGRESS')
        ->and($visit->fresh()->status)->toBe('IN_SERVICE')
        ->and(ServiceCompletionEvent::query()->where('visit_id', $visit->id)->count())->toBe(1);
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

it('supports a retail only visit and carries assigned staff into the product sale item', function () {
    $fx = servicingFixture();

    $queueResponse = $this->actingAs($fx['user'])->postJson('/api/v1/operations/queue/walk-ins', [
        'customer_id' => $fx['customer']->id,
        'customer_type' => 'NEW',
        'services' => [],
        'retail_items' => [[
            'item_name' => 'Retail Styling Clay',
            'quantity' => 1,
            'unit_price' => 350,
            'assigned_personnel_id' => $fx['personnel2']->id,
            'notes' => 'Retail only purchase',
        ]],
    ])->assertCreated()
        ->assertJsonPath('queue_entry.retail_items.0.assigned_personnel_id', $fx['personnel2']->id);

    $visitResponse = $this->actingAs($fx['user'])
        ->postJson('/api/v1/operations/queue/'.$queueResponse->json('queue_entry.id').'/start-servicing')
        ->assertOk()
        ->assertJsonPath('visit.status', 'IN_SERVICE')
        ->assertJsonPath('visit.retail_items.0.assigned_personnel_id', $fx['personnel2']->id);

    $visitId = $visitResponse->json('visit.id');

    $this->actingAs($fx['user'])
        ->postJson('/api/v1/operations/servicing/'.$visitId.'/complete')
        ->assertOk()
        ->assertJsonPath('visit.status', 'COMPLETED');

    $sale = Sale::query()->where('visit_id', $visitId)->firstOrFail()->fresh('items');
    $product = $sale->items->firstWhere('item_type', 'PRODUCT');

    expect($product)->not->toBeNull()
        ->and($product->assigned_personnel_id)->toBe($fx['personnel2']->id)
        ->and($product->assigned_personnel_name_snapshot)->toBe($fx['personnel2']->full_name)
        ->and((float) $product->line_total)->toBe(350.0);
});


it('automatically posts service recipe consumption once when a service is completed', function () {
    $fx = servicingFixture();
    $visit = createStartedVisit($this, $fx)->fresh('serviceLines');
    $line = $visit->serviceLines->first();

    $product = RetailProduct::query()->create([
        'business_id' => $fx['user']->business_id,
        'sku' => 'SKU-CONSUME-001',
        'name' => 'Scalp Serum Vial',
        'category' => 'Scalp Care',
        'brand' => 'Test',
        'unit' => 'Vial',
        'purchase_uom' => 'box',
        'purchase_to_basic_factor' => 10,
        'purchase_uom_cost' => 180,
        'basic_uom_cost' => 18,
        'selling_uom' => 'Vial',
        'selling_to_basic_factor' => 1,
        'consumption_uom' => 'ml',
        'consumption_to_basic_factor' => 8,
        'retail_available' => false,
        'stock_type' => 'INVENTORY',
        'unit_cost' => 18,
        'selling_price' => 0,
        'reorder_point' => 2,
        'commission_eligible' => false,
        'status' => 'ACTIVE',
    ]);

    InventoryStock::query()->create([
        'business_id' => $fx['user']->business_id,
        'branch_id' => $visit->branch_id,
        'retail_product_id' => $product->id,
        'on_hand' => 10,
        'reserved' => 0,
    ]);

    $recipe = ServiceConsumptionRecipe::query()->create([
        'business_id' => $fx['user']->business_id,
        'service_id' => $fx['service']->id,
        'status' => 'ACTIVE',
        'created_by' => $fx['user']->id,
        'updated_by' => $fx['user']->id,
    ]);
    $recipe->lines()->create([
        'retail_product_id' => $product->id,
        'quantity_per_service' => 4,
        'consumption_uom_snapshot' => 'ml',
        'consumption_to_basic_factor_snapshot' => 8,
        'basic_quantity_per_service' => 0.5,
        'basic_uom_snapshot' => 'Vial',
        'unit_cost_snapshot' => 18,
        'sort_order' => 1,
    ]);

    $this->actingAs($fx['user'])->postJson("/api/v1/operations/visit-services/{$line->id}/complete")->assertOk();
    $this->actingAs($fx['user'])->postJson("/api/v1/operations/visit-services/{$line->id}/complete")->assertOk();

    $stock = InventoryStock::query()->where('retail_product_id', $product->id)->firstOrFail();
    $consumption = ServiceConsumption::query()->with('lines')->where('visit_service_line_id', $line->id)->firstOrFail();
    $movement = InventoryMovement::query()->where('retail_product_id', $product->id)->where('movement_type', 'SERVICE_CONSUMPTION')->firstOrFail();

    expect((float) $stock->on_hand)->toBe(9.5)
        ->and(ServiceConsumption::query()->where('visit_service_line_id', $line->id)->count())->toBe(1)
        ->and((float) $consumption->total_cost)->toBe(9.0)
        ->and((float) $consumption->lines->first()->consumption_quantity)->toBe(4.0)
        ->and((float) $consumption->lines->first()->basic_quantity)->toBe(0.5)
        ->and((float) $movement->quantity_change)->toBe(-0.5)
        ->and((float) $movement->quantity_before)->toBe(10.0)
        ->and((float) $movement->quantity_after)->toBe(9.5);
});

it('consumes one proportional recipe share when a multi-session package session is completed', function () {
    $fx = servicingFixture();
    $groups = ReferenceGroup::query()->with('values')->get()->keyBy('code');
    $fx['service']->update([
        'service_count_reference_id' => $groups['SERVICE_COUNT']->values->firstWhere('code', 'MULTI_SESSION')->id,
        'number_of_sessions' => 6,
    ]);

    $visit = createStartedVisit($this, $fx)->fresh('serviceLines');
    $line = $visit->serviceLines->first();

    // Package entitlement may carry the package count on legacy lines, but one
    // completion is still one rendered session. The saved recipe below is the
    // TOTAL material allocation for all 6 sessions.
    $line->update([
        'source_type' => 'NEW_PACKAGE',
        'quantity' => 6,
    ]);

    $product = RetailProduct::query()->create([
        'business_id' => $fx['user']->business_id,
        'sku' => 'SKU-PACKAGE-CONSUME-001',
        'name' => 'Package Session Serum',
        'category' => 'Scalp Care',
        'brand' => 'Test',
        'unit' => 'Vial',
        'purchase_uom' => 'box',
        'purchase_to_basic_factor' => 10,
        'purchase_uom_cost' => 180,
        'basic_uom_cost' => 18,
        'selling_uom' => 'Vial',
        'selling_to_basic_factor' => 1,
        'consumption_uom' => 'ml',
        'consumption_to_basic_factor' => 8,
        'retail_available' => false,
        'stock_type' => 'INVENTORY',
        'unit_cost' => 18,
        'selling_price' => 0,
        'reorder_point' => 2,
        'commission_eligible' => false,
        'status' => 'ACTIVE',
    ]);

    InventoryStock::query()->create([
        'business_id' => $fx['user']->business_id,
        'branch_id' => $visit->branch_id,
        'retail_product_id' => $product->id,
        'on_hand' => 10,
        'reserved' => 0,
    ]);

    $recipe = ServiceConsumptionRecipe::query()->create([
        'business_id' => $fx['user']->business_id,
        'service_id' => $fx['service']->id,
        'status' => 'ACTIVE',
        'created_by' => $fx['user']->id,
        'updated_by' => $fx['user']->id,
    ]);
    $recipe->lines()->create([
        'retail_product_id' => $product->id,
        'quantity_per_service' => 24, // 24 ml TOTAL for the full 6-session package
        'consumption_uom_snapshot' => 'ml',
        'consumption_to_basic_factor_snapshot' => 8,
        'basic_quantity_per_service' => 3,
        'basic_uom_snapshot' => 'Vial',
        'unit_cost_snapshot' => 18,
        'sort_order' => 1,
    ]);

    $this->actingAs($fx['user'])
        ->postJson("/api/v1/operations/visit-services/{$line->id}/complete")
        ->assertOk();

    $consumption = ServiceConsumption::query()
        ->with('lines')
        ->where('visit_service_line_id', $line->id)
        ->firstOrFail();

    // 24 ml total / 6 sessions = 4 ml consumed for this one completed session.
    expect((float) $consumption->service_quantity)->toBe(1.0)
        ->and((float) $consumption->lines->first()->consumption_quantity)->toBe(4.0)
        ->and((float) $consumption->lines->first()->basic_quantity)->toBe(0.5)
        ->and((float) $consumption->total_cost)->toBe(9.0)
        ->and((float) InventoryStock::query()->where('retail_product_id', $product->id)->value('on_hand'))->toBe(9.5);
});

it('allows service completion to post negative stock when recipe materials are insufficient', function () {
    $fx = servicingFixture();
    $visit = createStartedVisit($this, $fx)->fresh('serviceLines');
    $line = $visit->serviceLines->first();

    $product = RetailProduct::query()->create([
        'business_id' => $fx['user']->business_id,
        'sku' => 'SKU-CONSUME-002',
        'name' => 'Low Stock Serum',
        'unit' => 'Vial',
        'purchase_uom' => 'Vial',
        'purchase_to_basic_factor' => 1,
        'purchase_uom_cost' => 18,
        'basic_uom_cost' => 18,
        'selling_uom' => 'Vial',
        'selling_to_basic_factor' => 1,
        'consumption_uom' => 'ml',
        'consumption_to_basic_factor' => 8,
        'retail_available' => false,
        'stock_type' => 'INVENTORY',
        'unit_cost' => 18,
        'selling_price' => 0,
        'reorder_point' => 0,
        'commission_eligible' => false,
        'status' => 'ACTIVE',
    ]);
    InventoryStock::query()->create([
        'business_id' => $fx['user']->business_id,
        'branch_id' => $visit->branch_id,
        'retail_product_id' => $product->id,
        'on_hand' => 0.25,
        'reserved' => 0,
    ]);
    $recipe = ServiceConsumptionRecipe::query()->create([
        'business_id' => $fx['user']->business_id,
        'service_id' => $fx['service']->id,
        'status' => 'ACTIVE',
        'created_by' => $fx['user']->id,
        'updated_by' => $fx['user']->id,
    ]);
    $recipe->lines()->create([
        'retail_product_id' => $product->id,
        'quantity_per_service' => 4,
        'consumption_uom_snapshot' => 'ml',
        'consumption_to_basic_factor_snapshot' => 8,
        'basic_quantity_per_service' => 0.5,
        'basic_uom_snapshot' => 'Vial',
        'unit_cost_snapshot' => 18,
        'sort_order' => 1,
    ]);

    $this->actingAs($fx['user'])
        ->postJson("/api/v1/operations/visit-services/{$line->id}/complete")
        ->assertOk();

    $consumption = ServiceConsumption::query()->with('lines')->where('visit_service_line_id', $line->id)->firstOrFail();
    $movement = InventoryMovement::query()->where('retail_product_id', $product->id)->where('movement_type', 'SERVICE_CONSUMPTION')->firstOrFail();

    expect($line->fresh()->status)->toBe('COMPLETED')
        ->and((float) InventoryStock::query()->where('retail_product_id', $product->id)->value('on_hand'))->toBe(-0.25)
        ->and(ServiceCompletionEvent::query()->where('visit_service_line_id', $line->id)->count())->toBe(1)
        ->and(ServiceConsumption::query()->where('visit_service_line_id', $line->id)->count())->toBe(1)
        ->and((float) $consumption->lines->first()->quantity_before)->toBe(0.25)
        ->and((float) $consumption->lines->first()->quantity_after)->toBe(-0.25)
        ->and((float) $movement->quantity_change)->toBe(-0.5)
        ->and((float) $movement->quantity_after)->toBe(-0.25);
});
