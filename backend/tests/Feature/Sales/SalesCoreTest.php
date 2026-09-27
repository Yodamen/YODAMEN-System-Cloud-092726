<?php

use App\Models\AccountingJournalEntry;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Personnel;
use App\Models\ReferenceGroup;
use App\Models\ReferenceValue;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\Visit;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\OperationsPermissionSeeder;
use Database\Seeders\SalesCorePermissionSeeder;
use Database\Seeders\ServicingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    $this->seed(FoundationSeeder::class);
    $this->seed(MasterDataSeeder::class);
    $this->seed(OperationsPermissionSeeder::class);
    $this->seed(ServicingPermissionSeeder::class);
    $this->seed(SalesCorePermissionSeeder::class);
});

function salesCoreFixture(): array
{
    $user = foundationUser();
    $groups = ReferenceGroup::query()->with('values')->get()->keyBy('code');

    $paymentGroup = $groups['PAYMENT_METHOD'];
    $cash = ReferenceValue::query()->create([
        'reference_group_id' => $paymentGroup->id,
        'code' => 'CASH',
        'display_value' => 'Cash',
        'sort_order' => 10,
        'is_system' => false,
        'active' => true,
    ]);
    mapCustomerPaymentMethod($user, $cash);

    $service = Service::query()->create([
        'business_id' => $user->business_id,
        'code' => 'SRV-SALES-001',
        'name' => 'Sales Haircut',
        'service_category_reference_id' => $groups['SERVICE_CATEGORY']->values->firstWhere('code', 'HAIRCUT')->id,
        'service_count_reference_id' => $groups['SERVICE_COUNT']->values->firstWhere('code', 'SINGLE_SESSION')->id,
        'number_of_sessions' => 1,
        'duration_minutes' => 45,
        'status_reference_id' => $groups['SERVICE_STATUS']->values->firstWhere('code', 'ACTIVE')->id,
    ]);

    ServicePrice::query()->create([
        'business_id' => $user->business_id,
        'service_id' => $service->id,
        'code' => 'PRC-SALES-001',
        'scope' => 'BRANCH',
        'branch_id' => $user->default_branch_id,
        'standard_price' => 500,
        'effective_from' => '2026-01-01',
        'status' => 'ACTIVE',
        'created_by' => $user->id,
    ]);

    $service2 = Service::query()->create([
        'business_id' => $user->business_id,
        'code' => 'SRV-SALES-002',
        'name' => 'Sales Shave',
        'service_category_reference_id' => $groups['SERVICE_CATEGORY']->values->firstWhere('code', 'HAIRCUT')->id,
        'service_count_reference_id' => $groups['SERVICE_COUNT']->values->firstWhere('code', 'SINGLE_SESSION')->id,
        'number_of_sessions' => 1,
        'duration_minutes' => 30,
        'status_reference_id' => $groups['SERVICE_STATUS']->values->firstWhere('code', 'ACTIVE')->id,
    ]);

    ServicePrice::query()->create([
        'business_id' => $user->business_id,
        'service_id' => $service2->id,
        'code' => 'PRC-SALES-002',
        'scope' => 'BRANCH',
        'branch_id' => $user->default_branch_id,
        'standard_price' => 300,
        'effective_from' => '2026-01-01',
        'status' => 'ACTIVE',
        'created_by' => $user->id,
    ]);

    $personnel = Personnel::query()->create([
        'business_id' => $user->business_id,
        'code' => 'EMP-SALES-001',
        'first_name' => 'Sales',
        'last_name' => 'Barber',
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
        'code' => 'CUS-SALES-001',
        'first_name' => 'Sales',
        'last_name' => 'Customer',
        'mobile_number' => '09175550000',
        'mobile_normalized' => '09175550000',
        'birthday' => '1990-05-10',
        'registration_date' => '2026-09-12',
        'status' => 'ACTIVE',
    ]);

    return compact('user', 'cash', 'service', 'service2', 'personnel', 'customer');
}

function salesCoreCompleteWalkIn($test, array $fx, array $services, ?array $advance = null): Visit
{
    $payload = [
        'customer_id' => $fx['customer']->id,
        'customer_type' => 'NEW',
        'services' => $services,
        'retail_items' => [],
    ];
    if ($advance) $payload['advance_payment'] = $advance;

    $queue = $test->actingAs($fx['user'])->postJson('/api/v1/operations/queue/walk-ins', $payload)->assertCreated();
    $visit = $test->actingAs($fx['user'])
        ->postJson('/api/v1/operations/queue/'.$queue->json('queue_entry.id').'/start-servicing')
        ->assertOk();

    $visitRecord = Visit::query()->findOrFail($visit->json('visit.id'))->fresh('serviceLines');
    foreach ($visitRecord->serviceLines as $line) {
        $test->actingAs($fx['user'])->postJson('/api/v1/operations/visit-services/'.$line->id.'/complete')->assertOk();
    }

    return $visitRecord->fresh();
}

it('records an advance payment on booking creation without creating a sale', function () {
    $fx = salesCoreFixture();

    $response = $this->actingAs($fx['user'])->postJson('/api/v1/operations/bookings', [
        'customer_id' => $fx['customer']->id,
        'customer_type' => 'NEW',
        'booking_date' => '2026-09-20',
        'booking_time' => '10:00',
        'booking_source' => 'RESERVATION',
        'services' => [['service_id' => $fx['service']->id, 'assigned_personnel_id' => $fx['personnel']->id]],
        'retail_items' => [],
        'advance_payment' => [
            'collected' => true,
            'amount' => 200,
            'payment_method_reference_id' => $fx['cash']->id,
            'reference_no' => 'ADV-001',
        ],
    ])->assertCreated();

    $payment = Payment::query()->firstOrFail();
    $journal = AccountingJournalEntry::query()
        ->where('source_type', 'PAYMENT')
        ->where('source_id', $payment->id)
        ->where('source_event', 'RECEIVE')
        ->with('lines')
        ->firstOrFail();

    expect($payment->payment_type)->toBe('ADVANCE')
        ->and((float) $payment->amount)->toBe(200.0)
        ->and($payment->booking_id)->toBe($response->json('booking.id'))
        ->and($payment->sale_id)->toBeNull()
        ->and(Sale::count())->toBe(0)
        ->and((float) $journal->lines->sum('debit'))->toBe(200.0)
        ->and((float) $journal->lines->sum('credit'))->toBe(200.0)
        ->and((float) $journal->lines->firstWhere('account_key', 'CASH_ON_HAND')->debit)->toBe(200.0)
        ->and((float) $journal->lines->firstWhere('account_key', 'CUSTOMER_ADVANCES')->credit)->toBe(200.0);
});

it('preserves the same advance payment through rebooking', function () {
    $fx = salesCoreFixture();
    $booking = $this->actingAs($fx['user'])->postJson('/api/v1/operations/bookings', [
        'customer_id' => $fx['customer']->id, 'customer_type' => 'NEW', 'booking_date' => '2026-09-20', 'booking_time' => '10:00',
        'booking_source' => 'RESERVATION', 'services' => [['service_id' => $fx['service']->id]], 'retail_items' => [],
        'advance_payment' => ['collected' => true, 'amount' => 150, 'payment_method_reference_id' => $fx['cash']->id],
    ])->assertCreated()->json('booking');

    $paymentId = Payment::query()->value('id');
    $this->actingAs($fx['user'])->postJson('/api/v1/operations/bookings/'.$booking['id'].'/rebook', [
        'booking_date' => '2026-09-21', 'booking_time' => '11:30', 'reason' => 'Moved schedule.',
    ])->assertOk();

    expect(Payment::count())->toBe(1)
        ->and(Payment::query()->value('id'))->toBe($paymentId)
        ->and(Payment::query()->value('booking_id'))->toBe($booking['id']);
});

it('creates exactly one sale and sale item set when a visit completes', function () {
    $fx = salesCoreFixture();
    $visit = salesCoreCompleteWalkIn($this, $fx, [[
        'service_id' => $fx['service']->id,
        'assigned_personnel_id' => $fx['personnel']->id,
    ]]);

    $sale = Sale::query()->firstOrFail();
    expect($sale->visit_id)->toBe($visit->id)
        ->and($sale->status)->toBe('FOR_CHECKOUT')
        ->and((float) $sale->gross_amount)->toBe(500.0)
        ->and(SaleItem::count())->toBe(1)
        ->and(SaleItem::query()->value('item_name_snapshot'))->toBe('Sales Haircut');

    $line = $visit->serviceLines()->firstOrFail();
    $this->actingAs($fx['user'])->postJson('/api/v1/operations/visit-services/'.$line->id.'/complete')->assertOk();
    expect(Sale::count())->toBe(1)->and(SaleItem::count())->toBe(1);
});

it('links received advances to the sale and marks a partially covered sale as partially paid', function () {
    $fx = salesCoreFixture();
    salesCoreCompleteWalkIn($this, $fx, [['service_id' => $fx['service']->id]], [
        'collected' => true, 'amount' => 200, 'payment_method_reference_id' => $fx['cash']->id,
    ]);

    $sale = Sale::query()->firstOrFail();
    $payment = Payment::query()->firstOrFail();
    expect($sale->status)->toBe('PARTIALLY_PAID')
        ->and((float) $sale->advance_payments)->toBe(200.0)
        ->and((float) $sale->outstanding_balance)->toBe(300.0)
        ->and($payment->sale_id)->toBe($sale->id)
        ->and($payment->visit_id)->toBe($sale->visit_id);
});

it('keeps a fully advance covered sale awaiting explicit financial confirmation', function () {
    $fx = salesCoreFixture();
    salesCoreCompleteWalkIn($this, $fx, [['service_id' => $fx['service']->id]], [
        'collected' => true, 'amount' => 500, 'payment_method_reference_id' => $fx['cash']->id,
    ]);

    $sale = Sale::query()->firstOrFail();
    expect($sale->status)->toBe('AWAITING_CONFIRM')
        ->and((float) $sale->outstanding_balance)->toBe(0.0)
        ->and($sale->financial_finalized_at)->toBeNull();
});

it('returns one pending parent row for a multi service sale', function () {
    $fx = salesCoreFixture();
    salesCoreCompleteWalkIn($this, $fx, [
        ['service_id' => $fx['service']->id],
        ['service_id' => $fx['service2']->id],
    ]);

    $this->actingAs($fx['user'])->getJson('/api/v1/sales/transactions/pending')
        ->assertOk()
        ->assertJsonCount(1, 'sales')
        ->assertJsonPath('sales.0.items_count', 2)
        ->assertJsonPath('sales.0.gross_amount', 800);
});

it('returns pending checkout detail with received advance payments without finalizing the sale', function () {
    $fx = salesCoreFixture();
    salesCoreCompleteWalkIn($this, $fx, [['service_id' => $fx['service']->id]], [
        'collected' => true, 'amount' => 100, 'payment_method_reference_id' => $fx['cash']->id,
    ]);
    $sale = Sale::query()->firstOrFail();

    $this->actingAs($fx['user'])->getJson('/api/v1/sales/transactions/'.$sale->id.'/checkout')
        ->assertOk()
        ->assertJsonPath('sale.code', $sale->code)
        ->assertJsonCount(1, 'sale.items')
        ->assertJsonCount(1, 'sale.payments')
        ->assertJsonPath('phase.checkout_enabled', true);

    expect($sale->fresh()->financial_finalized_at)->toBeNull();
});

it('grants advance collection permission to manager and reception but not barber', function () {
    foreach (['ROLE-MGR', 'ROLE-REC'] as $roleCode) {
        $role = Role::query()->where('code', $roleCode)->firstOrFail();
        expect($role->permissions()->pluck('code')->all())->toContain('payments.CREATE');
    }

    $barber = Role::query()->where('code', 'ROLE-BAR')->firstOrFail();
    expect($barber->permissions()->pluck('code')->all())->not->toContain('payments.CREATE');
});

it('exposes advance payment totals through booking queue and servicing stages', function () {
    $fx = salesCoreFixture();

    $queue = $this->actingAs($fx['user'])->postJson('/api/v1/operations/queue/walk-ins', [
        'customer_id' => $fx['customer']->id,
        'customer_type' => 'NEW',
        'services' => [['service_id' => $fx['service']->id]],
        'retail_items' => [],
        'advance_payment' => [
            'collected' => true,
            'amount' => 125,
            'payment_method_reference_id' => $fx['cash']->id,
        ],
    ])->assertCreated()
        ->assertJsonPath('queue_entry.advance_payment_total', 125);

    $bookingId = $queue->json('queue_entry.booking.id');
    $this->actingAs($fx['user'])
        ->getJson('/api/v1/operations/bookings/'.$bookingId)
        ->assertOk()
        ->assertJsonPath('booking.advance_payment_total', 125);

    $this->actingAs($fx['user'])
        ->postJson('/api/v1/operations/queue/'.$queue->json('queue_entry.id').'/start-servicing')
        ->assertOk()
        ->assertJsonPath('visit.advance_payment_total', 125);
});
