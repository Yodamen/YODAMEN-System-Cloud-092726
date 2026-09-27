<?php

use App\Models\Customer;
use App\Models\PackageEnrollment;
use App\Models\ReferenceGroup;
use App\Models\ReferenceValue;
use App\Models\Sale;
use App\Models\Service;
use App\Models\ServiceCredit;
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

function phase5aFixture(): array
{
    $user = foundationUser();
    $groups = ReferenceGroup::query()->with('values')->get()->keyBy('code');

    $cash = ReferenceValue::query()->firstOrCreate(
        ['reference_group_id' => $groups['PAYMENT_METHOD']->id, 'code' => 'CASH'],
        ['display_value' => 'Cash', 'sort_order' => 10, 'is_system' => false, 'active' => true],
    );
    mapCustomerPaymentMethod($user, $cash);

    $package = Service::query()->create([
        'business_id' => $user->business_id,
        'code' => 'SRV-P5-PKG-001',
        'name' => 'Five Session Grooming Package',
        'service_category_reference_id' => $groups['SERVICE_CATEGORY']->values->first()->id,
        'service_count_reference_id' => $groups['SERVICE_COUNT']->values->firstWhere('code', 'MULTI_SESSION')->id,
        'number_of_sessions' => 5,
        'duration_minutes' => 45,
        'status_reference_id' => $groups['SERVICE_STATUS']->values->firstWhere('code', 'ACTIVE')->id,
    ]);

    ServicePrice::query()->create([
        'business_id' => $user->business_id,
        'service_id' => $package->id,
        'code' => 'PRC-P5-PKG-001',
        'scope' => 'BRANCH',
        'branch_id' => $user->default_branch_id,
        'standard_price' => 1000,
        'effective_from' => '2026-01-01',
        'status' => 'ACTIVE',
        'created_by' => $user->id,
    ]);

    $customer = Customer::query()->create([
        'business_id' => $user->business_id,
        'code' => 'CUS-P5-001',
        'first_name' => 'Package',
        'last_name' => 'Customer',
        'mobile_number' => '09179990001',
        'mobile_normalized' => '09179990001',
        'registration_date' => '2026-09-13',
        'status' => 'ACTIVE',
    ]);

    return compact('user', 'cash', 'package', 'customer');
}

function phase5aCompletedPackageSale($test, array $fx): Sale
{
    $queue = $test->actingAs($fx['user'])->postJson('/api/v1/operations/queue/walk-ins', [
        'customer_id' => $fx['customer']->id,
        'customer_type' => 'RETURNEE',
        'services' => [['service_id' => $fx['package']->id]],
        'retail_items' => [],
    ])->assertCreated();

    $visitResponse = $test->actingAs($fx['user'])
        ->postJson('/api/v1/operations/queue/'.$queue->json('queue_entry.id').'/start-servicing')
        ->assertOk();

    $visit = Visit::query()->findOrFail($visitResponse->json('visit.id'))->fresh('serviceLines');
    $line = $visit->serviceLines->first();

    $test->actingAs($fx['user'])
        ->postJson('/api/v1/operations/visit-services/'.$line->id.'/complete')
        ->assertOk();

    return Sale::query()->where('visit_id', $visit->id)->firstOrFail()->fresh('items');
}

it('creates one pending package enrollment for a new multi session booking line', function () {
    $fx = phase5aFixture();

    $response = $this->actingAs($fx['user'])->postJson('/api/v1/operations/bookings', [
        'customer_id' => $fx['customer']->id,
        'customer_type' => 'RETURNEE',
        'booking_date' => '2026-09-20',
        'booking_time' => '10:00',
        'booking_source' => 'RESERVATION',
        'services' => [['service_id' => $fx['package']->id, 'quantity' => 5]],
        'retail_items' => [],
    ])->assertCreated();

    $line = \App\Models\BookingServiceLine::query()->where('booking_id', $response->json('booking.id'))->firstOrFail();
    $enrollment = PackageEnrollment::query()->firstOrFail();

    expect($line->source_type)->toBe('NEW_PACKAGE')
        ->and($line->quantity)->toBe(1)
        ->and($line->package_enrollment_id)->toBe($enrollment->id)
        ->and($enrollment->status)->toBe('PENDING_CHECKOUT')
        ->and($enrollment->total_sessions)->toBe(5)
        ->and((float) $enrollment->package_price_snapshot)->toBe(1000.0);
});

it('activates an enrollment at checkout and recognizes the first completed package session', function () {
    $fx = phase5aFixture();
    $sale = phase5aCompletedPackageSale($this, $fx);
    $item = $sale->items->first();

    expect($item->item_type)->toBe('PACKAGE')
        ->and($item->package_enrollment_id)->not->toBeNull();

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$sale->id.'/finalize-checkout', [
        'idempotency_key' => 'p5a-package-paid',
        'advance_allocations' => [],
        'amount_collected_now' => 1000,
        'payment_method_reference_id' => $fx['cash']->id,
        'checkout_allocations' => [['sale_item_id' => $item->id, 'amount' => 1000]],
        'allow_remaining_balance' => false,
    ])->assertOk()->assertJsonPath('sale.status', 'PAID');

    $enrollment = PackageEnrollment::query()->firstOrFail();
    $credit = ServiceCredit::query()->firstOrFail();

    expect($enrollment->status)->toBe('ACTIVE')
        ->and($enrollment->sessions_completed_before_activation)->toBe(1)
        ->and($enrollment->origin_sale_item_id)->toBe($item->id)
        ->and($credit->package_enrollment_id)->toBe($enrollment->id)
        ->and($credit->sessions_purchased)->toBe(5)
        ->and($credit->sessions_used)->toBe(1)
        ->and($credit->sessions_remaining)->toBe(4);

    $this->actingAs($fx['user'])->getJson('/api/v1/service-balances')
        ->assertOk()
        ->assertJsonCount(1, 'balances')
        ->assertJsonPath('balances.0.purchased', 5)
        ->assertJsonPath('balances.0.used', 1)
        ->assertJsonPath('balances.0.remaining', 4)
        ->assertJsonPath('balances.0.total_paid', 1000)
        ->assertJsonPath('balances.0.balance', 0);

    $this->actingAs($fx['user'])
        ->getJson('/api/v1/package-enrollments/'.$enrollment->id.'/financials')
        ->assertOk()
        ->assertJsonPath('package.payment_history.0.booking.code', $sale->booking->code);
});

it('activates package entitlement even when the finalized package purchase remains on account', function () {
    $fx = phase5aFixture();
    $sale = phase5aCompletedPackageSale($this, $fx);
    $item = $sale->items->first();

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$sale->id.'/finalize-checkout', [
        'idempotency_key' => 'p5a-package-on-account',
        'advance_allocations' => [],
        'amount_collected_now' => 0,
        'payment_method_reference_id' => null,
        'checkout_allocations' => [],
        'allow_remaining_balance' => true,
    ])->assertOk()->assertJsonPath('sale.status', 'ON_ACCOUNT');

    expect(ServiceCredit::query()->value('sessions_remaining'))->toBe(4)
        ->and(PackageEnrollment::query()->value('status'))->toBe('ACTIVE');

    $this->actingAs($fx['user'])->getJson('/api/v1/service-balances')
        ->assertOk()
        ->assertJsonPath('balances.0.total_paid', 0)
        ->assertJsonPath('balances.0.balance', 1000);
});

it('keeps repeat purchases of the same package as separate enrollments', function () {
    $fx = phase5aFixture();

    foreach (['p5a-repeat-1', 'p5a-repeat-2'] as $key) {
        $sale = phase5aCompletedPackageSale($this, $fx);
        $item = $sale->items->first();
        $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$sale->id.'/finalize-checkout', [
            'idempotency_key' => $key,
            'advance_allocations' => [],
            'amount_collected_now' => 1000,
            'payment_method_reference_id' => $fx['cash']->id,
            'checkout_allocations' => [['sale_item_id' => $item->id, 'amount' => 1000]],
            'allow_remaining_balance' => false,
        ])->assertOk();
    }

    expect(PackageEnrollment::count())->toBe(2)
        ->and(ServiceCredit::count())->toBe(2)
        ->and(PackageEnrollment::query()->distinct()->count('origin_sale_item_id'))->toBe(2);
});

function phase5bActivatedCredit($test, array $fx): ServiceCredit
{
    $sale = phase5aCompletedPackageSale($test, $fx);
    $item = $sale->items->first();

    $test->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$sale->id.'/finalize-checkout', [
        'idempotency_key' => 'p5b-credit-'.uniqid(),
        'advance_allocations' => [],
        'amount_collected_now' => 1000,
        'payment_method_reference_id' => $fx['cash']->id,
        'checkout_allocations' => [['sale_item_id' => $item->id, 'amount' => 1000]],
        'allow_remaining_balance' => false,
    ])->assertOk();

    return ServiceCredit::query()->latest('id')->firstOrFail();
}

it('detects active Service Credits for the selected customer and reports available sessions', function () {
    $fx = phase5aFixture();
    $credit = phase5bActivatedCredit($this, $fx);

    $this->actingAs($fx['user'])
        ->getJson('/api/v1/operations/customers/'.$fx['customer']->id.'/service-credits?booking_date=2026-09-20')
        ->assertOk()
        ->assertJsonCount(1, 'service_credits')
        ->assertJsonPath('service_credits.0.id', $credit->id)
        ->assertJsonPath('service_credits.0.sessions_remaining', 4)
        ->assertJsonPath('service_credits.0.reserved_sessions', 0)
        ->assertJsonPath('service_credits.0.available_sessions', 4);
});

it('lets a Booking reserve an available Service Credit session and reduces bookable availability', function () {
    $fx = phase5aFixture();
    $credit = phase5bActivatedCredit($this, $fx);

    $response = $this->actingAs($fx['user'])->postJson('/api/v1/operations/bookings', [
        'customer_id' => $fx['customer']->id,
        'customer_type' => 'RETURNEE',
        'booking_date' => '2026-09-20',
        'booking_time' => '10:00',
        'booking_source' => 'RESERVATION',
        'services' => [[
            'service_id' => $fx['package']->id,
            'service_credit_id' => $credit->id,
            'quantity' => 1,
        ]],
        'retail_items' => [],
    ])->assertCreated();

    $line = \App\Models\BookingServiceLine::query()->where('booking_id', $response->json('booking.id'))->firstOrFail();
    expect($line->source_type)->toBe('SERVICE_CREDIT')
        ->and($line->service_credit_id)->toBe($credit->id)
        ->and($line->package_enrollment_id)->toBe($credit->package_enrollment_id)
        ->and($line->quantity)->toBe(1);

    $this->actingAs($fx['user'])
        ->getJson('/api/v1/operations/customers/'.$fx['customer']->id.'/service-credits?booking_date=2026-09-20')
        ->assertOk()
        ->assertJsonPath('service_credits.0.sessions_remaining', 4)
        ->assertJsonPath('service_credits.0.reserved_sessions', 1)
        ->assertJsonPath('service_credits.0.available_sessions', 3);
});

it('prevents active bookings from reserving more Service Credit sessions than remain available', function () {
    $fx = phase5aFixture();
    $credit = phase5bActivatedCredit($this, $fx);

    foreach (range(1, 4) as $index) {
        $this->actingAs($fx['user'])->postJson('/api/v1/operations/bookings', [
            'customer_id' => $fx['customer']->id,
            'customer_type' => 'RETURNEE',
            'booking_date' => '2026-09-'.(20 + $index),
            'booking_time' => '10:00',
            'booking_source' => 'RESERVATION',
            'services' => [[
                'service_id' => $fx['package']->id,
                'service_credit_id' => $credit->id,
            ]],
            'retail_items' => [],
        ])->assertCreated();
    }

    $this->actingAs($fx['user'])->postJson('/api/v1/operations/bookings', [
        'customer_id' => $fx['customer']->id,
        'customer_type' => 'RETURNEE',
        'booking_date' => '2026-09-30',
        'booking_time' => '10:00',
        'booking_source' => 'RESERVATION',
        'services' => [[
            'service_id' => $fx['package']->id,
            'service_credit_id' => $credit->id,
        ]],
        'retail_items' => [],
    ])->assertStatus(422)
        ->assertJsonPath('message', 'All remaining sessions for the selected Service Credit are already reserved by other active bookings.');
});

it('consumes one Service Credit session when the credited service is completed', function () {
    $fx = phase5aFixture();
    $credit = phase5bActivatedCredit($this, $fx);

    $queue = $this->actingAs($fx['user'])->postJson('/api/v1/operations/queue/walk-ins', [
        'customer_id' => $fx['customer']->id,
        'customer_type' => 'RETURNEE',
        'services' => [[
            'service_id' => $fx['package']->id,
            'service_credit_id' => $credit->id,
        ]],
        'retail_items' => [],
    ])->assertCreated();

    $visitResponse = $this->actingAs($fx['user'])
        ->postJson('/api/v1/operations/queue/'.$queue->json('queue_entry.id').'/start-servicing')
        ->assertOk();

    $visit = Visit::query()->findOrFail($visitResponse->json('visit.id'))->fresh('serviceLines');
    $line = $visit->serviceLines->first();

    expect($line->source_type)->toBe('SERVICE_CREDIT')
        ->and($line->service_credit_id)->toBe($credit->id);

    $this->actingAs($fx['user'])
        ->postJson('/api/v1/operations/visit-services/'.$line->id.'/complete')
        ->assertOk();

    $credit->refresh();
    expect($credit->sessions_used)->toBe(2)
        ->and($credit->sessions_remaining)->toBe(3)
        ->and(\App\Models\ServiceCreditUsage::query()->where('visit_service_line_id', $line->id)->count())->toBe(1);

    $sale = Sale::query()->where('visit_id', $visit->id)->firstOrFail()->fresh('items');
    expect($sale->items->first()->item_type)->toBe('SERVICE_CREDIT_USE')
        ->and((float) $sale->items->first()->line_total)->toBe(0.0);
});

it('applies an existing Advance through a Service Credit claim to the source package and classifies the Booking as On Account', function () {
    $fx = phase5aFixture();

    $packageSale = phase5aCompletedPackageSale($this, $fx);
    $packageItem = $packageSale->items->first();

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$packageSale->id.'/finalize-checkout', [
        'idempotency_key' => 'p5b-source-package-on-account-advance',
        'advance_allocations' => [],
        'amount_collected_now' => 0,
        'payment_method_reference_id' => null,
        'checkout_allocations' => [],
    ])->assertOk()->assertJsonPath('sale.status', 'ON_ACCOUNT');

    $credit = ServiceCredit::query()->firstOrFail();

    $queue = $this->actingAs($fx['user'])->postJson('/api/v1/operations/queue/walk-ins', [
        'customer_id' => $fx['customer']->id,
        'customer_type' => 'RETURNEE',
        'services' => [[
            'service_id' => $fx['package']->id,
            'service_credit_id' => $credit->id,
        ]],
        'retail_items' => [],
        'payment_collection' => [
            'collected' => true,
            'amount' => 250,
            'advance_amount' => 250,
            'payment_method_reference_id' => $fx['cash']->id,
            'allocations' => [],
        ],
    ])->assertCreated();

    $visitResponse = $this->actingAs($fx['user'])
        ->postJson('/api/v1/operations/queue/'.$queue->json('queue_entry.id').'/start-servicing')
        ->assertOk();

    $visit = Visit::query()->findOrFail($visitResponse->json('visit.id'))->fresh('serviceLines');
    $this->actingAs($fx['user'])
        ->postJson('/api/v1/operations/visit-services/'.$visit->serviceLines->first()->id.'/complete')
        ->assertOk();

    $creditSale = Sale::query()->where('visit_id', $visit->id)->firstOrFail()->fresh(['items', 'booking']);
    $creditItem = $creditSale->items->first();

    $this->actingAs($fx['user'])
        ->getJson('/api/v1/sales/transactions/'.$creditSale->id.'/checkout')
        ->assertOk()
        ->assertJsonPath('allocation.advance_required_allocation', 250)
        ->assertJsonPath('allocation.items.0.service_credit_interim_eligible', true);

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$creditSale->id.'/finalize-checkout', [
        'idempotency_key' => 'p5b-service-credit-advance-partial',
        'advance_allocations' => [[
            'sale_item_id' => $creditItem->id,
            'amount' => 250,
        ]],
        'amount_collected_now' => 0,
        'payment_method_reference_id' => null,
        'checkout_allocations' => [],
    ])->assertOk()
        ->assertJsonPath('sale.status', 'SERVICE_CREDIT')
        ->assertJsonPath('sale.transaction_classification', 'ON_ACCOUNT');

    expect((float) $packageItem->fresh()->balance_amount)->toBe(750.0)
        ->and((float) $packageSale->fresh()->outstanding_balance)->toBe(750.0);

    $this->actingAs($fx['user'])
        ->getJson('/api/v1/sales/transactions/settlement-lines?classification=ON_ACCOUNT&search='.urlencode($creditSale->booking->code))
        ->assertOk()
        ->assertJsonCount(1, 'lines')
        ->assertJsonPath('lines.0.payment_amount', 250)
        ->assertJsonPath('lines.0.balance_amount', 750)
        ->assertJsonPath('lines.0.payment_details.0.application_type', 'ADVANCE');
});

it('allows checkout payment through a Service Credit row as Interim against the source package balance', function () {
    $fx = phase5aFixture();

    $packageSale = phase5aCompletedPackageSale($this, $fx);
    $packageItem = $packageSale->items->first();

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$packageSale->id.'/finalize-checkout', [
        'idempotency_key' => 'p5b-source-package-on-account',
        'advance_allocations' => [],
        'amount_collected_now' => 0,
        'payment_method_reference_id' => null,
        'checkout_allocations' => [],
        'allow_remaining_balance' => true,
    ])->assertOk()->assertJsonPath('sale.status', 'ON_ACCOUNT');

    $credit = ServiceCredit::query()->firstOrFail();

    $queue = $this->actingAs($fx['user'])->postJson('/api/v1/operations/queue/walk-ins', [
        'customer_id' => $fx['customer']->id,
        'customer_type' => 'RETURNEE',
        'services' => [[
            'service_id' => $fx['package']->id,
            'service_credit_id' => $credit->id,
        ]],
        'retail_items' => [],
    ])->assertCreated();

    $visitResponse = $this->actingAs($fx['user'])
        ->postJson('/api/v1/operations/queue/'.$queue->json('queue_entry.id').'/start-servicing')
        ->assertOk();

    $visit = Visit::query()->findOrFail($visitResponse->json('visit.id'))->fresh('serviceLines');
    $this->actingAs($fx['user'])
        ->postJson('/api/v1/operations/visit-services/'.$visit->serviceLines->first()->id.'/complete')
        ->assertOk();

    $creditSale = Sale::query()->where('visit_id', $visit->id)->firstOrFail()->fresh(['items', 'booking']);
    $creditItem = $creditSale->items->first();

    $this->actingAs($fx['user'])
        ->getJson('/api/v1/sales/transactions/'.$creditSale->id.'/checkout')
        ->assertOk()
        ->assertJsonPath('allocation.items.0.service_credit_interim_eligible', true)
        ->assertJsonPath('allocation.items.0.service_credit_package_balance', 1000)
        ->assertJsonPath('allocation.items.0.service_credit_package_booking_code', $packageSale->booking->code);

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$creditSale->id.'/finalize-checkout', [
        'idempotency_key' => 'p5b-service-credit-interim-partial',
        'advance_allocations' => [],
        'amount_collected_now' => 300,
        'payment_method_reference_id' => $fx['cash']->id,
        'checkout_allocations' => [[
            'sale_item_id' => $creditItem->id,
            'amount' => 300,
        ]],
    ])->assertOk()
        ->assertJsonPath('sale.status', 'SERVICE_CREDIT')
        ->assertJsonPath('sale.transaction_classification', 'ON_ACCOUNT');

    $packageSale->refresh();
    $packageItem->refresh();

    expect($packageSale->status)->toBe('ON_ACCOUNT')
        ->and((float) $packageSale->outstanding_balance)->toBe(700.0)
        ->and((float) $packageItem->balance_amount)->toBe(700.0);

    $application = \App\Models\PaymentApplication::query()
        ->where('target_sale_item_id', $packageItem->id)
        ->where('target_type', 'ON_ACCOUNT_LINE')
        ->latest('id')
        ->firstOrFail();

    expect((float) $application->amount)->toBe(300.0)
        ->and(data_get($application->target_snapshot, 'source'))->toBe('SERVICE_CREDIT_INTERIM_CHECKOUT')
        ->and($application->payment->booking_id)->toBe($creditSale->booking_id);


    $this->actingAs($fx['user'])
        ->getJson('/api/v1/sales/transactions/settlement-lines?classification=ON_ACCOUNT&search='.urlencode($creditSale->booking->code))
        ->assertOk()
        ->assertJsonCount(1, 'lines')
        ->assertJsonPath('lines.0.booking.code', $creditSale->booking->code)
        ->assertJsonPath('lines.0.payment_amount', 300)
        ->assertJsonPath('lines.0.balance_amount', 700)
        ->assertJsonPath('lines.0.payment_details.0.application_type', 'CHECKOUT');

    $this->actingAs($fx['user'])
        ->getJson('/api/v1/sales/transactions/settlement-lines?classification=SERVICE_CREDIT&search='.urlencode($creditSale->booking->code))
        ->assertOk()
        ->assertJsonCount(0, 'lines');
});

it('moves a package purchase Booking from On Account to Paid once its full balance is settled through a Service Credit checkout Interim payment', function () {
    $fx = phase5aFixture();

    $packageSale = phase5aCompletedPackageSale($this, $fx);
    $packageItem = $packageSale->items->first();

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$packageSale->id.'/finalize-checkout', [
        'idempotency_key' => 'p5b-source-package-on-account-full',
        'advance_allocations' => [],
        'amount_collected_now' => 0,
        'payment_method_reference_id' => null,
        'checkout_allocations' => [],
        'allow_remaining_balance' => true,
    ])->assertOk()->assertJsonPath('sale.status', 'ON_ACCOUNT');

    $credit = ServiceCredit::query()->firstOrFail();

    $queue = $this->actingAs($fx['user'])->postJson('/api/v1/operations/queue/walk-ins', [
        'customer_id' => $fx['customer']->id,
        'customer_type' => 'RETURNEE',
        'services' => [[
            'service_id' => $fx['package']->id,
            'service_credit_id' => $credit->id,
        ]],
        'retail_items' => [],
    ])->assertCreated();

    $visitResponse = $this->actingAs($fx['user'])
        ->postJson('/api/v1/operations/queue/'.$queue->json('queue_entry.id').'/start-servicing')
        ->assertOk();

    $visit = Visit::query()->findOrFail($visitResponse->json('visit.id'))->fresh('serviceLines');
    $this->actingAs($fx['user'])
        ->postJson('/api/v1/operations/visit-services/'.$visit->serviceLines->first()->id.'/complete')
        ->assertOk();

    $creditSale = Sale::query()->where('visit_id', $visit->id)->firstOrFail()->fresh('items');
    $creditItem = $creditSale->items->first();

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$creditSale->id.'/finalize-checkout', [
        'idempotency_key' => 'p5b-service-credit-interim-full',
        'advance_allocations' => [],
        'amount_collected_now' => 1000,
        'payment_method_reference_id' => $fx['cash']->id,
        'checkout_allocations' => [[
            'sale_item_id' => $creditItem->id,
            'amount' => 1000,
        ]],
    ])->assertOk()
        ->assertJsonPath('sale.status', 'SERVICE_CREDIT')
        ->assertJsonPath('sale.transaction_classification', 'PAID');

    $packageSale->refresh();
    $packageItem->refresh();

    expect($packageSale->status)->toBe('PAID')
        ->and((float) $packageSale->outstanding_balance)->toBe(0.0)
        ->and($packageItem->status)->toBe('PAID')
        ->and((float) $packageItem->balance_amount)->toBe(0.0);

    $this->actingAs($fx['user'])
        ->getJson('/api/v1/sales/transactions/settlement-lines?classification=ON_ACCOUNT&search='.$packageSale->booking->code)
        ->assertOk()
        ->assertJsonCount(0, 'lines');

    $this->actingAs($fx['user'])
        ->getJson('/api/v1/sales/transactions/settlement-lines?classification=PAID&search='.$packageSale->booking->code)
        ->assertOk()
        ->assertJsonCount(1, 'lines');

    $this->actingAs($fx['user'])
        ->getJson('/api/v1/sales/transactions/settlement-lines?classification=PAID&search='.urlencode($creditSale->booking->code))
        ->assertOk()
        ->assertJsonCount(1, 'lines')
        ->assertJsonPath('lines.0.booking.code', $creditSale->booking->code)
        ->assertJsonPath('lines.0.payment_amount', 1000)
        ->assertJsonPath('lines.0.balance_amount', 0);
});

it('requires a Booking Interim payment tied to a claimed package to be manually confirmed in Collected at checkout without applying it twice', function () {
    $fx = phase5aFixture();

    $packageSale = phase5aCompletedPackageSale($this, $fx);
    $packageItem = $packageSale->items->first();

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$packageSale->id.'/finalize-checkout', [
        'idempotency_key' => 'p5b-booking-interim-confirm-source',
        'advance_allocations' => [],
        'amount_collected_now' => 0,
        'payment_method_reference_id' => null,
        'checkout_allocations' => [],
        'allow_remaining_balance' => true,
    ])->assertOk()->assertJsonPath('sale.status', 'ON_ACCOUNT');

    $credit = ServiceCredit::query()->firstOrFail();

    $queue = $this->actingAs($fx['user'])->postJson('/api/v1/operations/queue/walk-ins', [
        'customer_id' => $fx['customer']->id,
        'customer_type' => 'RETURNEE',
        'services' => [[
            'service_id' => $fx['package']->id,
            'service_credit_id' => $credit->id,
        ]],
        'retail_items' => [],
        'payment_collection' => [
            'collected' => true,
            'amount' => 250,
            'advance_amount' => 0,
            'payment_method_reference_id' => $fx['cash']->id,
            'allocations' => [[
                'sale_item_id' => $packageItem->id,
                'amount' => 250,
            ]],
        ],
    ])->assertCreated()->assertJsonPath('queue_entry.interim_payment_total', 250);

    expect((float) $packageItem->fresh()->balance_amount)->toBe(750.0);

    $visitResponse = $this->actingAs($fx['user'])
        ->postJson('/api/v1/operations/queue/'.$queue->json('queue_entry.id').'/start-servicing')
        ->assertOk();

    $visit = Visit::query()->findOrFail($visitResponse->json('visit.id'))->fresh('serviceLines');
    $this->actingAs($fx['user'])
        ->postJson('/api/v1/operations/visit-services/'.$visit->serviceLines->first()->id.'/complete')
        ->assertOk();

    $creditSale = Sale::query()->where('visit_id', $visit->id)->firstOrFail()->fresh(['items', 'booking']);
    $creditItem = $creditSale->items->first();

    $this->actingAs($fx['user'])
        ->getJson('/api/v1/sales/transactions/'.$creditSale->id.'/checkout')
        ->assertOk()
        ->assertJsonPath('allocation.booking_interim_confirmation_required', 250)
        ->assertJsonPath('allocation.items.0.booking_interim_confirmation_amount', 250)
        ->assertJsonPath('allocation.items.0.service_credit_package_balance', 750);

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$creditSale->id.'/finalize-checkout', [
        'idempotency_key' => 'p5b-booking-interim-confirm-missing',
        'collected_allocations' => [[
            'sale_item_id' => $creditItem->id,
            'amount' => 0,
        ]],
        'advance_allocations' => [],
        'amount_collected_now' => 0,
        'payment_method_reference_id' => null,
        'checkout_allocations' => [],
    ])->assertStatus(422);

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$creditSale->id.'/finalize-checkout', [
        'idempotency_key' => 'p5b-booking-interim-confirmed',
        'collected_allocations' => [[
            'sale_item_id' => $creditItem->id,
            'amount' => 250,
        ]],
        'advance_allocations' => [],
        'amount_collected_now' => 0,
        'payment_method_reference_id' => null,
        'checkout_allocations' => [],
    ])->assertOk()
        ->assertJsonPath('sale.status', 'SERVICE_CREDIT')
        ->assertJsonPath('sale.transaction_classification', 'ON_ACCOUNT');

    expect((float) $packageItem->fresh()->balance_amount)->toBe(750.0)
        ->and((float) $packageSale->fresh()->outstanding_balance)->toBe(750.0);

    $this->actingAs($fx['user'])
        ->getJson('/api/v1/sales/transactions/settlement-lines?classification=ON_ACCOUNT&search='.urlencode($creditSale->booking->code))
        ->assertOk()
        ->assertJsonCount(1, 'lines')
        ->assertJsonPath('lines.0.booking.code', $creditSale->booking->code)
        ->assertJsonPath('lines.0.payment_amount', 250)
        ->assertJsonPath('lines.0.payment_type_summary', 'Interim');
});

it('reverses recognized package revenue when the originating package transaction is fully reversed', function () {
    $fx = phase5aFixture();
    $sale = phase5aCompletedPackageSale($this, $fx);
    $item = $sale->items->first();

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$sale->id.'/finalize-checkout', [
        'idempotency_key' => 'p5a-package-reversal-accounting',
        'advance_allocations' => [],
        'amount_collected_now' => 1000,
        'payment_method_reference_id' => $fx['cash']->id,
        'checkout_allocations' => [['sale_item_id' => $item->id, 'amount' => 1000]],
        'allow_remaining_balance' => false,
    ])->assertOk()->assertJsonPath('sale.status', 'PAID');

    $recognizedBefore = (float) \App\Models\AccountingJournalLine::query()
        ->where('account_key', 'PACKAGE_REVENUE')
        ->selectRaw('COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) AS net_amount')
        ->value('net_amount');
    expect($recognizedBefore)->toBe(200.0);

    $request = $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$sale->id.'/action-requests', [
        'action_type' => 'REVERSAL',
        'reason_code' => 'TEST_TRANSACTION',
        'remarks' => 'Accounting regression test for package-origin reversal.',
        'lines' => [],
    ])->assertCreated();

    $requestId = (int) $request->json('request.id');
    $this->actingAs($fx['user'])
        ->postJson('/api/v1/sales/transaction-action-requests/'.$requestId.'/approve', [
            'manager_remarks' => 'Approved test reversal.',
        ])
        ->assertOk()
        ->assertJsonPath('request.status', 'POSTED');

    $recognizedAfter = (float) \App\Models\AccountingJournalLine::query()
        ->where('account_key', 'PACKAGE_REVENUE')
        ->selectRaw('COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) AS net_amount')
        ->value('net_amount');
    $deferredAfter = (float) \App\Models\AccountingJournalLine::query()
        ->where('account_key', 'DEFERRED_PACKAGE_REVENUE')
        ->selectRaw('COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) AS net_amount')
        ->value('net_amount');

    expect(round($recognizedAfter, 2))->toBe(0.0)
        ->and(round($deferredAfter, 2))->toBe(0.0)
        ->and(\App\Models\AccountingJournalEntry::query()
            ->where('source_type', 'TRANSACTION_ACTION')
            ->where('source_id', $requestId)
            ->where('source_event', 'PACKAGE_REVENUE_REVERSAL')
            ->exists())->toBeTrue();
});
