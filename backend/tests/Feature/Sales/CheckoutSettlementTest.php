<?php

use App\Models\AccountingJournalEntry;
use App\Models\CommissionEarning;
use App\Models\CommissionRule;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Payment;
use App\Models\PaymentApplication;
use App\Models\Personnel;
use App\Models\ReferenceGroup;
use App\Models\ReferenceValue;
use App\Models\RetailProduct;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\Visit;
use Database\Seeders\CheckoutSettlementPermissionSeeder;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\OperationsPermissionSeeder;
use Database\Seeders\SalesCorePermissionSeeder;
use Database\Seeders\ServicingPermissionSeeder;
use App\Services\Sales\SalesReportingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    $this->seed(FoundationSeeder::class);
    $this->seed(MasterDataSeeder::class);
    $this->seed(OperationsPermissionSeeder::class);
    $this->seed(ServicingPermissionSeeder::class);
    $this->seed(SalesCorePermissionSeeder::class);
    $this->seed(CheckoutSettlementPermissionSeeder::class);
});

function phase4Fixture(): array
{
    $user = foundationUser();
    $groups = ReferenceGroup::query()->with('values')->get()->keyBy('code');
    $cash = ReferenceValue::query()->create([
        'reference_group_id' => $groups['PAYMENT_METHOD']->id,
        'code' => 'CASH-P4', 'display_value' => 'Cash', 'sort_order' => 10, 'active' => true,
    ]);
    mapCustomerPaymentMethod($user, $cash);

    $services = collect([
        ['code' => 'SRV-P4-A', 'name' => 'Phase 4 Haircut', 'price' => 500, 'count' => 'SINGLE_SESSION', 'sessions' => 1],
        ['code' => 'SRV-P4-B', 'name' => 'Phase 4 Shave', 'price' => 300, 'count' => 'SINGLE_SESSION', 'sessions' => 1],
        ['code' => 'SRV-P4-PKG-A', 'name' => 'Phase 4 Haircut Package', 'price' => 500, 'count' => 'MULTI_SESSION', 'sessions' => 5],
        ['code' => 'SRV-P4-PKG-B', 'name' => 'Phase 4 Shave Package', 'price' => 300, 'count' => 'MULTI_SESSION', 'sessions' => 3],
    ])->map(function ($row) use ($user, $groups) {
        $service = Service::query()->create([
            'business_id' => $user->business_id,
            'code' => $row['code'], 'name' => $row['name'],
            'service_category_reference_id' => $groups['SERVICE_CATEGORY']->values->firstWhere('code', 'HAIRCUT')->id,
            'service_count_reference_id' => $groups['SERVICE_COUNT']->values->firstWhere('code', $row['count'])->id,
            'number_of_sessions' => $row['sessions'], 'duration_minutes' => 30,
            'status_reference_id' => $groups['SERVICE_STATUS']->values->firstWhere('code', 'ACTIVE')->id,
        ]);
        ServicePrice::query()->create([
            'business_id' => $user->business_id, 'service_id' => $service->id,
            'code' => 'PRC-'.$row['code'], 'scope' => 'BRANCH', 'branch_id' => $user->default_branch_id,
            'standard_price' => $row['price'], 'effective_from' => '2026-01-01', 'status' => 'ACTIVE', 'created_by' => $user->id,
        ]);
        return $service;
    })->values();

    $personnel = Personnel::query()->create([
        'business_id' => $user->business_id, 'code' => 'EMP-P4-001', 'first_name' => 'Phase', 'last_name' => 'Barber',
        'position_reference_id' => $groups['EMPLOYEE_POSITION']->values->firstWhere('code', 'BARBER')->id,
        'personnel_type_reference_id' => $groups['PERSONNEL_TYPE']->values->firstWhere('code', 'EMPLOYEE')->id,
        'compensation_rule_reference_id' => $groups['COMPENSATION_RULE']->values->firstWhere('code', 'HOURLY_PLUS_COMMISSION')->id,
        'hourly_rate' => 100, 'can_perform_services' => true, 'commission_eligible' => true, 'status' => 'ACTIVE',
    ]);

    $customer = Customer::query()->create([
        'business_id' => $user->business_id, 'code' => 'CUS-P4-001', 'first_name' => 'Phase', 'last_name' => 'Customer',
        'mobile_number' => '09176660000', 'mobile_normalized' => '09176660000', 'birthday' => '1990-06-01',
        'registration_date' => '2026-09-12', 'status' => 'ACTIVE',
    ]);

    return [
        'user' => $user,
        'cash' => $cash,
        'serviceA' => $services[0],
        'serviceB' => $services[1],
        'packageA' => $services[2],
        'packageB' => $services[3],
        'personnel' => $personnel,
        'customer' => $customer,
    ];
}

function phase4Sale($test, array $fx, array $serviceIds, float $advance = 0): Sale
{
    $payload = [
        'customer_id' => $fx['customer']->id, 'customer_type' => 'NEW',
        'services' => array_map(fn ($id) => ['service_id' => $id, 'assigned_personnel_id' => $fx['personnel']->id], $serviceIds),
        'retail_items' => [],
    ];
    if ($advance > 0) $payload['advance_payment'] = ['collected' => true, 'amount' => $advance, 'payment_method_reference_id' => $fx['cash']->id];

    $queue = $test->actingAs($fx['user'])->postJson('/api/v1/operations/queue/walk-ins', $payload)->assertCreated();
    $visitResponse = $test->actingAs($fx['user'])->postJson('/api/v1/operations/queue/'.$queue->json('queue_entry.id').'/start-servicing')->assertOk();
    $visit = Visit::query()->findOrFail($visitResponse->json('visit.id'))->fresh('serviceLines');
    foreach ($visit->serviceLines as $line) {
        $test->actingAs($fx['user'])->postJson('/api/v1/operations/visit-services/'.$line->id.'/complete')->assertOk();
    }
    return Sale::query()->where('visit_id', $visit->id)->firstOrFail()->fresh('items');
}


function phase4RetailSale($test, array $fx, string $itemName = 'Retail Pomade', int $quantity = 2, float $unitPrice = 200): Sale
{
    $product = RetailProduct::query()->create([
        'business_id' => $fx['user']->business_id,
        'sku' => 'SKU-P4-RETAIL-'.strtoupper(substr(md5($itemName), 0, 8)),
        'name' => $itemName,
        'category' => 'Test Retail',
        'unit' => 'pc',
        'purchase_uom' => 'pc',
        'purchase_to_basic_factor' => 1,
        'selling_uom' => 'pc',
        'selling_to_basic_factor' => 1,
        'stock_type' => 'INVENTORY',
        'retail_available' => true,
        'unit_cost' => max(1, round($unitPrice * 0.5, 2)),
        'selling_price' => $unitPrice,
        'reorder_point' => 1,
        'commission_eligible' => true,
        'status' => 'ACTIVE',
        'created_by' => $fx['user']->id,
        'updated_by' => $fx['user']->id,
    ]);

    InventoryStock::query()->create([
        'business_id' => $fx['user']->business_id,
        'branch_id' => $fx['user']->default_branch_id,
        'retail_product_id' => $product->id,
        'on_hand' => max(20, $quantity + 5),
        'reserved' => 0,
    ]);

    $queue = $test->actingAs($fx['user'])->postJson('/api/v1/operations/queue/walk-ins', [
        'customer_id' => $fx['customer']->id,
        'customer_type' => 'NEW',
        'services' => [],
        'retail_items' => [[
            'retail_product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'assigned_personnel_id' => $fx['personnel']->id,
            'notes' => null,
        ]],
    ])->assertCreated();

    $visitResponse = $test->actingAs($fx['user'])
        ->postJson('/api/v1/operations/queue/'.$queue->json('queue_entry.id').'/start-servicing')
        ->assertOk();

    $visit = Visit::query()->findOrFail($visitResponse->json('visit.id'));

    $test->actingAs($fx['user'])
        ->postJson('/api/v1/operations/servicing/'.$visit->id.'/complete')
        ->assertOk()
        ->assertJsonPath('visit.status', 'COMPLETED');

    return Sale::query()->where('visit_id', $visit->id)->firstOrFail()->fresh('items');
}

it('finalizes a fully paid checkout and creates payment applications', function () {
    $fx = phase4Fixture();
    $sale = phase4Sale($this, $fx, [$fx['serviceA']->id]);
    $item = $sale->items->first();

    $proofToken = $this->supportingDocumentToken($fx['user'], 'sales', 'checkout-transfer-proof.pdf');

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$sale->id.'/finalize-checkout', [
        'idempotency_key' => 'p4-full-paid-1',
        'attachment_tokens' => [$proofToken],
        'advance_allocations' => [],
        'amount_collected_now' => 500,
        'payment_method_reference_id' => $fx['cash']->id,
        'reference_no' => 'CHK-001',
        'checkout_allocations' => [['sale_item_id' => $item->id, 'amount' => 500]],
        'allow_remaining_balance' => false,
    ])->assertOk()->assertJsonPath('sale.status', 'PAID')->assertJsonPath('sale.outstanding_balance', 0);

    $documents = $this->actingAs($fx['user'])
        ->getJson('/api/v1/supporting-documents?entity_type=SALE_CHECKOUT&entity_id='.$sale->id)
        ->assertOk()
        ->assertJsonCount(1, 'documents')
        ->assertJsonPath('documents.0.name', 'checkout-transfer-proof.pdf');
    $this->actingAs($fx['user'])
        ->get('/api/v1/supporting-documents/'.$documents->json('documents.0.id').'/download')
        ->assertOk();

    expect(Payment::query()->where('payment_type', 'CHECKOUT')->count())->toBe(1)
        ->and(PaymentApplication::count())->toBe(1)
        ->and((float) $item->fresh()->balance_amount)->toBe(0.0)
        ->and($item->fresh()->status)->toBe('PAID')
        ->and($sale->fresh()->financial_finalized_at)->not->toBeNull();

    $saleJournal = AccountingJournalEntry::query()
        ->where('source_type', 'SALE')->where('source_id', $sale->id)->where('source_event', 'FINALIZE')
        ->with('lines')->firstOrFail();
    $payment = Payment::query()->where('payment_type', 'CHECKOUT')->firstOrFail();
    $paymentJournal = AccountingJournalEntry::query()
        ->where('source_type', 'PAYMENT')->where('source_id', $payment->id)->where('source_event', 'RECEIVE')
        ->with('lines')->firstOrFail();
    $application = PaymentApplication::query()->firstOrFail();
    $applicationJournal = AccountingJournalEntry::query()
        ->where('source_type', 'PAYMENT_APPLICATION')->where('source_id', $application->id)->where('source_event', 'APPLY')
        ->with('lines')->firstOrFail();

    expect((float) $saleJournal->lines->firstWhere('account_key', 'AR_CUSTOMERS')->debit)->toBe(500.0)
        ->and((float) $saleJournal->lines->firstWhere('account_key', 'SERVICE_REVENUE')->credit)->toBe(500.0)
        ->and((float) $paymentJournal->lines->firstWhere('account_key', 'CASH_ON_HAND')->debit)->toBe(500.0)
        ->and((float) $paymentJournal->lines->firstWhere('account_key', 'CUSTOMER_ADVANCES')->credit)->toBe(500.0)
        ->and((float) $applicationJournal->lines->firstWhere('account_key', 'CUSTOMER_ADVANCES')->debit)->toBe(500.0)
        ->and((float) $applicationJournal->lines->firstWhere('account_key', 'AR_CUSTOMERS')->credit)->toBe(500.0);

    $sale->refresh();
    $bookingCode = $sale->booking()->value('code');
    $saleDate = $sale->sale_date->format('Y-m-d');

    $this->actingAs($fx['user'])->getJson('/api/v1/sales/transactions/settlement-lines?classification=PAID'
        .'&booking_id='.urlencode($bookingCode)
        .'&customer_name='.urlencode('Phase Customer')
        .'&service_name='.urlencode('Phase 4 Haircut')
        .'&staff_name='.urlencode('Phase Barber')
        .'&start_date='.$saleDate
        .'&end_date='.$saleDate)
        ->assertOk()
        ->assertJsonCount(1, 'lines')
        ->assertJsonPath('lines.0.booking.code', $bookingCode);

    $this->actingAs($fx['user'])->getJson('/api/v1/operations/bookings?view=COMPLETED&payment=PAID')
        ->assertOk()
        ->assertJsonCount(1, 'bookings')
        ->assertJsonPath('bookings.0.code', $bookingCode)
        ->assertJsonPath('bookings.0.payment_status', 'PAID');
});

it('allocates advance and checkout manually across two lines and finalizes remaining balance on account', function () {
    $fx = phase4Fixture();
    $sale = phase4Sale($this, $fx, [$fx['serviceA']->id, $fx['packageB']->id], 500);
    $items = $sale->items->values();

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$sale->id.'/finalize-checkout', [
        'idempotency_key' => 'p4-mixed-1',
        'advance_allocations' => [['sale_item_id' => $items[0]->id, 'amount' => 500]],
        'amount_collected_now' => 100,
        'payment_method_reference_id' => $fx['cash']->id,
        'checkout_allocations' => [['sale_item_id' => $items[1]->id, 'amount' => 100]],
        'allow_remaining_balance' => true,
    ])->assertOk()->assertJsonPath('sale.status', 'ON_ACCOUNT')->assertJsonPath('sale.outstanding_balance', 200);

    expect($items[0]->fresh()->status)->toBe('PAID')
        ->and($items[1]->fresh()->status)->toBe('ON_ACCOUNT')
        ->and((float) $items[1]->fresh()->balance_amount)->toBe(200.0)
        ->and(PaymentApplication::count())->toBe(2);
});

it('rejects leaving a single-session service balance even when remaining balance is allowed', function () {
    $fx = phase4Fixture();
    $sale = phase4Sale($this, $fx, [$fx['serviceA']->id]);
    $item = $sale->items->first();

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$sale->id.'/finalize-checkout', [
        'idempotency_key' => 'p4-single-session-no-balance',
        'advance_allocations' => [],
        'amount_collected_now' => 100,
        'payment_method_reference_id' => $fx['cash']->id,
        'checkout_allocations' => [['sale_item_id' => $item->id, 'amount' => 100]],
        'allow_remaining_balance' => true,
    ])->assertStatus(422)
        ->assertJsonPath('message', 'Single-session services and product purchases must be fully paid at checkout. Only multi-session packages may carry a remaining balance.');

    expect($sale->fresh()->financial_finalized_at)->toBeNull()
        ->and(Payment::query()->where('payment_type', 'CHECKOUT')->count())->toBe(0)
        ->and(PaymentApplication::count())->toBe(0);
});

it('derives on account status from the checkout amount for a multi-session package', function () {
    $fx = phase4Fixture();
    $sale = phase4Sale($this, $fx, [$fx['packageA']->id]);
    $item = $sale->items->first();

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$sale->id.'/finalize-checkout', [
        'idempotency_key' => 'p4-derived-on-account',
        'advance_allocations' => [],
        'amount_collected_now' => 100,
        'payment_method_reference_id' => $fx['cash']->id,
        'checkout_allocations' => [['sale_item_id' => $item->id, 'amount' => 100]],
    ])->assertOk()
        ->assertJsonPath('sale.status', 'ON_ACCOUNT')
        ->assertJsonPath('sale.outstanding_balance', 400);

    expect($sale->fresh()->financial_finalized_at)->not->toBeNull()
        ->and(Payment::query()->where('payment_type', 'CHECKOUT')->count())->toBe(1);
});

it('finalizes a multi-session package on account when checkout confirms no final collection', function () {
    $fx = phase4Fixture();
    $sale = phase4Sale($this, $fx, [$fx['packageA']->id]);

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$sale->id.'/finalize-checkout', [
        'idempotency_key' => 'p4-zero-final-collection',
        'advance_allocations' => [],
        'amount_collected_now' => 0,
        'payment_method_reference_id' => null,
        'checkout_allocations' => [],
    ])->assertOk()
        ->assertJsonPath('sale.status', 'ON_ACCOUNT')
        ->assertJsonPath('sale.outstanding_balance', 500);

    expect(Payment::query()->where('payment_type', 'CHECKOUT')->count())->toBe(0)
        ->and($sale->fresh()->financial_finalized_at)->not->toBeNull();
});

it('finalizes a fully advance covered sale without a new checkout payment', function () {
    $fx = phase4Fixture();
    $sale = phase4Sale($this, $fx, [$fx['serviceA']->id], 500);
    $item = $sale->items->first();

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$sale->id.'/finalize-checkout', [
        'idempotency_key' => 'p4-advance-only',
        'advance_allocations' => [['sale_item_id' => $item->id, 'amount' => 500]],
        'amount_collected_now' => 0,
        'payment_method_reference_id' => null,
        'checkout_allocations' => [],
        'allow_remaining_balance' => false,
    ])->assertOk()->assertJsonPath('sale.status', 'PAID');

    expect(Payment::query()->where('payment_type', 'CHECKOUT')->count())->toBe(0)
        ->and(PaymentApplication::count())->toBe(1);
});

it('splits finalized service lines across transaction tabs while retaining the same Booking ID', function () {
    $fx = phase4Fixture();
    $sale = phase4Sale($this, $fx, [$fx['serviceA']->id, $fx['packageB']->id]);
    $items = $sale->items->values();

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$sale->id.'/finalize-checkout', [
        'idempotency_key' => 'p4-tabs', 'advance_allocations' => [], 'amount_collected_now' => 500,
        'payment_method_reference_id' => $fx['cash']->id,
        'checkout_allocations' => [['sale_item_id' => $items[0]->id, 'amount' => 500]], 'allow_remaining_balance' => true,
    ])->assertOk();

    $this->actingAs($fx['user'])->getJson('/api/v1/sales/transactions/settlement-lines?classification=PAID')
        ->assertOk()
        ->assertJsonCount(1, 'lines')
        ->assertJsonPath('lines.0.booking.code', $sale->booking->code)
        ->assertJsonPath('lines.0.item_names.0', 'Phase 4 Haircut')
        ->assertJsonPath('lines.0.payment_amount', 500)
        ->assertJsonPath('lines.0.balance_amount', 0);

    $this->actingAs($fx['user'])->getJson('/api/v1/sales/transactions/settlement-lines?classification=ON_ACCOUNT')
        ->assertOk()
        ->assertJsonCount(1, 'lines')
        ->assertJsonPath('lines.0.booking.code', $sale->booking->code)
        ->assertJsonPath('lines.0.item_names.0', 'Phase 4 Shave Package')
        ->assertJsonPath('lines.0.payment_amount', 0)
        ->assertJsonPath('lines.0.balance_amount', 300);
});

it('records an interim payment from a newly created booking and applies it to older on account balances', function () {
    $fx = phase4Fixture();
    $oldSaleA = phase4Sale($this, $fx, [$fx['packageA']->id]);
    $oldItemA = $oldSaleA->items->first();
    $oldSaleB = phase4Sale($this, $fx, [$fx['packageB']->id]);
    $oldItemB = $oldSaleB->items->first();

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$oldSaleA->id.'/finalize-checkout', [
        'idempotency_key' => 'p4-on-account-a', 'advance_allocations' => [], 'amount_collected_now' => 100,
        'payment_method_reference_id' => $fx['cash']->id,
        'checkout_allocations' => [['sale_item_id' => $oldItemA->id, 'amount' => 100]], 'allow_remaining_balance' => true,
    ])->assertOk();

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$oldSaleB->id.'/finalize-checkout', [
        'idempotency_key' => 'p4-on-account-b', 'advance_allocations' => [], 'amount_collected_now' => 0,
        'payment_method_reference_id' => null,
        'checkout_allocations' => [], 'allow_remaining_balance' => true,
    ])->assertOk();

    $response = $this->actingAs($fx['user'])->postJson('/api/v1/operations/bookings', [
        'customer_id' => $fx['customer']->id,
        'customer_type' => 'RETURNEE',
        'booking_date' => '2026-09-20',
        'booking_time' => '10:00',
        'booking_source' => 'RESERVATION',
        'services' => [['service_id' => $fx['serviceA']->id, 'assigned_personnel_id' => $fx['personnel']->id]],
        'retail_items' => [],
        'payment_collection' => [
            'collected' => true,
            'amount' => 500,
            'advance_amount' => 0,
            'payment_method_reference_id' => $fx['cash']->id,
            'allocations' => [
                ['sale_item_id' => $oldItemA->id, 'amount' => 400],
                ['sale_item_id' => $oldItemB->id, 'amount' => 100],
            ],
        ],
    ])->assertCreated()->assertJsonPath('booking.interim_payment_total', 500);

    $sourceBookingId = $response->json('booking.id');
    $payment = Payment::query()->where('payment_type', 'INTERIM')->latest('id')->firstOrFail();

    expect($payment->booking_id)->toBe($sourceBookingId)
        ->and($payment->sale_id)->toBeNull()
        ->and((float) $payment->amount)->toBe(500.0)
        ->and(PaymentApplication::query()->where('payment_id', $payment->id)->count())->toBe(2)
        ->and($oldItemA->fresh()->status)->toBe('PAID')
        ->and((float) $oldItemA->fresh()->balance_amount)->toBe(0.0)
        ->and($oldItemB->fresh()->status)->toBe('ON_ACCOUNT')
        ->and((float) $oldItemB->fresh()->balance_amount)->toBe(200.0)
        ->and((float) $oldSaleA->fresh()->outstanding_balance)->toBe(0.0)
        ->and((float) $oldSaleB->fresh()->outstanding_balance)->toBe(200.0);

    $this->actingAs($fx['user'])
        ->getJson('/api/v1/sales/transactions/settlement-lines?classification=PAID&search='.urlencode($oldSaleA->booking->code))
        ->assertOk()
        ->assertJsonPath('lines.0.booking.code', $oldSaleA->booking->code)
        ->assertJsonPath('lines.0.payment_amount', 100)
        ->assertJsonPath('lines.0.payment_details.0.application_type', 'CHECKOUT');
});

it('records an interim payment from a newly created walk in and keeps the payment linked to that walk in booking', function () {
    $fx = phase4Fixture();
    $oldSale = phase4Sale($this, $fx, [$fx['packageA']->id]);
    $oldItem = $oldSale->items->first();

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$oldSale->id.'/finalize-checkout', [
        'idempotency_key' => 'p4-walkin-on-account', 'advance_allocations' => [], 'amount_collected_now' => 100,
        'payment_method_reference_id' => $fx['cash']->id,
        'checkout_allocations' => [['sale_item_id' => $oldItem->id, 'amount' => 100]], 'allow_remaining_balance' => true,
    ])->assertOk();

    $response = $this->actingAs($fx['user'])->postJson('/api/v1/operations/queue/walk-ins', [
        'customer_id' => $fx['customer']->id,
        'customer_type' => 'RETURNEE',
        'services' => [['service_id' => $fx['serviceB']->id, 'assigned_personnel_id' => $fx['personnel']->id]],
        'retail_items' => [],
        'payment_collection' => [
            'collected' => true,
            'amount' => 200,
            'advance_amount' => 0,
            'payment_method_reference_id' => $fx['cash']->id,
            'allocations' => [['sale_item_id' => $oldItem->id, 'amount' => 200]],
        ],
    ])->assertCreated()->assertJsonPath('queue_entry.interim_payment_total', 200);

    $newBookingId = $response->json('queue_entry.booking.id');
    $payment = Payment::query()->where('payment_type', 'INTERIM')->latest('id')->firstOrFail();

    expect($payment->booking_id)->toBe($newBookingId)
        ->and((float) $payment->amount)->toBe(200.0)
        ->and((float) $oldItem->fresh()->balance_amount)->toBe(200.0);
});

it('records one mixed receipt and splits it between the new booking advance and an older on account line', function () {
    $fx = phase4Fixture();
    $oldSale = phase4Sale($this, $fx, [$fx['packageA']->id]);
    $oldItem = $oldSale->items->first();

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$oldSale->id.'/finalize-checkout', [
        'idempotency_key' => 'p4-mixed-receipt-old-account', 'advance_allocations' => [], 'amount_collected_now' => 100,
        'payment_method_reference_id' => $fx['cash']->id,
        'checkout_allocations' => [['sale_item_id' => $oldItem->id, 'amount' => 100]], 'allow_remaining_balance' => true,
    ])->assertOk();

    $response = $this->actingAs($fx['user'])->postJson('/api/v1/operations/bookings', [
        'customer_id' => $fx['customer']->id,
        'customer_type' => 'RETURNEE',
        'booking_date' => '2026-09-21',
        'booking_time' => '11:00',
        'booking_source' => 'RESERVATION',
        'services' => [['service_id' => $fx['serviceB']->id, 'assigned_personnel_id' => $fx['personnel']->id]],
        'retail_items' => [],
        'payment_collection' => [
            'collected' => true,
            'amount' => 500,
            'advance_amount' => 200,
            'payment_method_reference_id' => $fx['cash']->id,
            'allocations' => [['sale_item_id' => $oldItem->id, 'amount' => 300]],
        ],
    ])->assertCreated()
        ->assertJsonPath('booking.advance_payment_total', 200)
        ->assertJsonPath('booking.interim_payment_total', 300);

    $bookingId = $response->json('booking.id');
    $payment = Payment::query()->where('booking_id', $bookingId)->latest('id')->firstOrFail();

    expect($payment->payment_type)->toBe('MIXED')
        ->and((float) $payment->amount)->toBe(500.0)
        ->and(Payment::query()->where('booking_id', $bookingId)->count())->toBe(1)
        ->and(PaymentApplication::query()->where('payment_id', $payment->id)->where('target_type', 'ADVANCE_RESERVE')->sum('amount'))->toEqual('200.00')
        ->and(PaymentApplication::query()->where('payment_id', $payment->id)->where('target_type', 'ON_ACCOUNT_LINE')->sum('amount'))->toEqual('300.00')
        ->and((float) $oldItem->fresh()->balance_amount)->toBe(100.0);
});

it('carries only the advance portion of a mixed walk in receipt into the new sale checkout', function () {
    $fx = phase4Fixture();
    $oldSale = phase4Sale($this, $fx, [$fx['packageA']->id]);
    $oldItem = $oldSale->items->first();

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$oldSale->id.'/finalize-checkout', [
        'idempotency_key' => 'p4-mixed-lifecycle-old', 'advance_allocations' => [], 'amount_collected_now' => 100,
        'payment_method_reference_id' => $fx['cash']->id,
        'checkout_allocations' => [['sale_item_id' => $oldItem->id, 'amount' => 100]], 'allow_remaining_balance' => true,
    ])->assertOk();

    $queue = $this->actingAs($fx['user'])->postJson('/api/v1/operations/queue/walk-ins', [
        'customer_id' => $fx['customer']->id,
        'customer_type' => 'RETURNEE',
        'services' => [['service_id' => $fx['serviceB']->id, 'assigned_personnel_id' => $fx['personnel']->id]],
        'retail_items' => [],
        'payment_collection' => [
            'collected' => true,
            'amount' => 400,
            'advance_amount' => 200,
            'payment_method_reference_id' => $fx['cash']->id,
            'allocations' => [['sale_item_id' => $oldItem->id, 'amount' => 200]],
        ],
    ])->assertCreated()
        ->assertJsonPath('queue_entry.advance_payment_total', 200)
        ->assertJsonPath('queue_entry.interim_payment_total', 200);

    $visitResponse = $this->actingAs($fx['user'])
        ->postJson('/api/v1/operations/queue/'.$queue->json('queue_entry.id').'/start-servicing')
        ->assertOk()
        ->assertJsonPath('visit.advance_payment_total', 200)
        ->assertJsonPath('visit.interim_payment_total', 200);
    $visit = Visit::query()->findOrFail($visitResponse->json('visit.id'))->fresh('serviceLines');
    $this->actingAs($fx['user'])->postJson('/api/v1/operations/visit-services/'.$visit->serviceLines->first()->id.'/complete')->assertOk();

    $sale = Sale::query()->where('visit_id', $visit->id)->firstOrFail()->fresh('items');

    $this->actingAs($fx['user'])
        ->getJson('/api/v1/sales/transactions/pending?search='.urlencode($sale->booking->code))
        ->assertOk()
        ->assertJsonPath('sales.0.booking_advance_payment_total', 200)
        ->assertJsonPath('sales.0.booking_interim_payment_total', 200);

    expect((float) $sale->advance_payments)->toBe(200.0)
        ->and((float) $sale->outstanding_balance)->toBe(100.0)
        ->and($sale->status)->toBe('PARTIALLY_PAID');

    $item = $sale->items->first();
    $checkout = $this->actingAs($fx['user'])->getJson('/api/v1/sales/transactions/'.$sale->id.'/checkout')->assertOk();
    expect((float) $checkout->json('allocation.advance_available'))->toBe(200.0);

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$sale->id.'/finalize-checkout', [
        'idempotency_key' => 'p4-mixed-lifecycle-new',
        'advance_allocations' => [['sale_item_id' => $item->id, 'amount' => 200]],
        'amount_collected_now' => 100,
        'payment_method_reference_id' => $fx['cash']->id,
        'checkout_allocations' => [['sale_item_id' => $item->id, 'amount' => 100]],
        'allow_remaining_balance' => false,
    ])->assertOk()->assertJsonPath('sale.status', 'PAID');

    $this->actingAs($fx['user'])
        ->getJson('/api/v1/sales/transactions/settlement-lines?classification=PAID&search='.urlencode($sale->booking->code))
        ->assertOk()
        ->assertJsonPath('lines.0.booking.code', $sale->booking->code)
        ->assertJsonPath('lines.0.payment_amount', 300);

    $payment = Payment::query()->where('booking_id', $sale->booking_id)->where('payment_type', 'MIXED')->firstOrFail();
    expect(PaymentApplication::query()->where('payment_id', $payment->id)->where('target_type', 'ADVANCE_LINE')->sum('amount'))->toEqual('200.00')
        ->and(PaymentApplication::query()->where('payment_id', $payment->id)->where('target_type', 'ON_ACCOUNT_LINE')->sum('amount'))->toEqual('200.00');
});

it('replays duplicate checkout submission without creating duplicate payment effects', function () {
    $fx = phase4Fixture();
    $sale = phase4Sale($this, $fx, [$fx['serviceA']->id]);
    $item = $sale->items->first();
    $payload = [
        'idempotency_key' => 'p4-idempotent-finalize', 'advance_allocations' => [], 'amount_collected_now' => 500,
        'payment_method_reference_id' => $fx['cash']->id,
        'checkout_allocations' => [['sale_item_id' => $item->id, 'amount' => 500]], 'allow_remaining_balance' => false,
    ];

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$sale->id.'/finalize-checkout', $payload)->assertOk();
    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$sale->id.'/finalize-checkout', $payload)->assertOk();

    expect(Payment::query()->where('payment_type', 'CHECKOUT')->count())->toBe(1)
        ->and(PaymentApplication::count())->toBe(1);
});

it('rejects line over allocation without leaving partial financial writes', function () {
    $fx = phase4Fixture();
    $sale = phase4Sale($this, $fx, [$fx['serviceA']->id]);
    $item = $sale->items->first();

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$sale->id.'/finalize-checkout', [
        'idempotency_key' => 'p4-overallocate', 'advance_allocations' => [], 'amount_collected_now' => 600,
        'payment_method_reference_id' => $fx['cash']->id,
        'checkout_allocations' => [['sale_item_id' => $item->id, 'amount' => 600]], 'allow_remaining_balance' => false,
    ])->assertStatus(422);

    expect(Payment::query()->where('payment_type', 'CHECKOUT')->count())->toBe(0)
        ->and(PaymentApplication::count())->toBe(0)
        ->and($sale->fresh()->financial_finalized_at)->toBeNull();
});

it('reports only payments applied to the specific on account line', function () {
    $fx = phase4Fixture();
    $sale = phase4Sale($this, $fx, [$fx['serviceA']->id, $fx['packageB']->id]);
    $items = $sale->items->values();
    $single = $items[0];
    $package = $items[1];

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$sale->id.'/finalize-checkout', [
        'idempotency_key' => 'p4-line-specific-on-account-total',
        'advance_allocations' => [],
        'amount_collected_now' => 600,
        'payment_method_reference_id' => $fx['cash']->id,
        'checkout_allocations' => [
            ['sale_item_id' => $single->id, 'amount' => 500],
            ['sale_item_id' => $package->id, 'amount' => 100],
        ],
        'on_account_allocations' => [],
    ])->assertOk()->assertJsonPath('sale.status', 'ON_ACCOUNT');

    $this->actingAs($fx['user'])
        ->getJson('/api/v1/sales/transactions/settlement-lines?classification=ON_ACCOUNT&search='.urlencode($sale->booking->code))
        ->assertOk()
        ->assertJsonCount(1, 'lines')
        ->assertJsonPath('lines.0.booking.code', $sale->booking->code)
        ->assertJsonPath('lines.0.payment_amount', 100)
        ->assertJsonPath('lines.0.payment_type_summary', 'Checkout')
        ->assertJsonPath('lines.0.balance_amount', 200)
        ->assertJsonPath('lines.0.payment_details.0.application_type', 'CHECKOUT');
});

it('keeps interim on the older on account booking while checkout pays only the current booking', function () {
    $fx = phase4Fixture();
    $oldSale = phase4Sale($this, $fx, [$fx['packageA']->id]);
    $oldItem = $oldSale->items->first();

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$oldSale->id.'/finalize-checkout', [
        'idempotency_key' => 'p4-old-package-open-for-interim-current-checkout',
        'advance_allocations' => [],
        'amount_collected_now' => 0,
        'payment_method_reference_id' => null,
        'checkout_allocations' => [],
    ])->assertOk()->assertJsonPath('sale.status', 'ON_ACCOUNT');

    $queue = $this->actingAs($fx['user'])->postJson('/api/v1/operations/queue/walk-ins', [
        'customer_id' => $fx['customer']->id,
        'customer_type' => 'RETURNEE',
        'services' => [['service_id' => $fx['serviceB']->id, 'assigned_personnel_id' => $fx['personnel']->id]],
        'retail_items' => [],
        'payment_collection' => [
            'collected' => true,
            'amount' => 100,
            'advance_amount' => 0,
            'payment_method_reference_id' => $fx['cash']->id,
            'allocations' => [['sale_item_id' => $oldItem->id, 'amount' => 100]],
        ],
    ])->assertCreated()->assertJsonPath('queue_entry.interim_payment_total', 100);

    $visitResponse = $this->actingAs($fx['user'])
        ->postJson('/api/v1/operations/queue/'.$queue->json('queue_entry.id').'/start-servicing')
        ->assertOk();
    $visit = Visit::query()->findOrFail($visitResponse->json('visit.id'))->fresh('serviceLines');
    $this->actingAs($fx['user'])->postJson('/api/v1/operations/visit-services/'.$visit->serviceLines->first()->id.'/complete')->assertOk();

    $currentSale = Sale::query()->where('visit_id', $visit->id)->firstOrFail()->fresh('items');
    $currentItem = $currentSale->items->first();

    $this->actingAs($fx['user'])
        ->getJson('/api/v1/sales/transactions/'.$currentSale->id.'/checkout')
        ->assertOk()
        ->assertJsonPath('sale.booking_interim_payment_total', 100)
        ->assertJsonPath('allocation.current_chargeable_balance', 300);

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$currentSale->id.'/finalize-checkout', [
        'idempotency_key' => 'p4-interim-old-plus-checkout-current-booking',
        'advance_allocations' => [],
        'amount_collected_now' => 300,
        'payment_method_reference_id' => $fx['cash']->id,
        'checkout_allocations' => [['sale_item_id' => $currentItem->id, 'amount' => 300]],
    ])->assertOk()
        ->assertJsonPath('sale.status', 'PAID')
        ->assertJsonPath('sale.booking_interim_payment_total', 100)
        ->assertJsonPath('sale.booking_checkout_payment_total', 300)
        ->assertJsonPath('sale.booking_collection_total', 400);

    expect((float) $oldItem->fresh()->balance_amount)->toBe(400.0)
        ->and((float) $oldSale->fresh()->outstanding_balance)->toBe(400.0)
        ->and((float) $currentSale->fresh()->outstanding_balance)->toBe(0.0);

    $this->actingAs($fx['user'])
        ->getJson('/api/v1/sales/transactions/settlement-lines?classification=ON_ACCOUNT&search='.urlencode($oldSale->booking->code))
        ->assertOk()
        ->assertJsonPath('lines.0.booking.code', $oldSale->booking->code)
        ->assertJsonPath('lines.0.payment_amount', 0);

    $this->actingAs($fx['user'])
        ->getJson('/api/v1/sales/transactions/settlement-lines?classification=PAID&search='.urlencode($currentSale->booking->code))
        ->assertOk()
        ->assertJsonPath('lines.0.booking.code', $currentSale->booking->code)
        ->assertJsonPath('lines.0.payment_amount', 300)
        ->assertJsonCount(1, 'lines.0.payment_details')
        ->assertJsonPath('lines.0.payment_details.0.application_type', 'CHECKOUT');
});

it('previews and finalizes checkout using the net amount after a discount code', function () {
    $fx = phase4Fixture();
    $sale = phase4Sale($this, $fx, [$fx['serviceA']->id]);
    $item = $sale->items->first();

    $this->actingAs($fx['user'])->postJson('/api/v1/admin/discount-rules', [
        'rule_name' => 'Haircut Save 20',
        'discount_code' => 'SAVE20',
        'branch_id' => null,
        'applies_to' => 'ALL',
        'customer_id' => null,
        'customer_type' => null,
        'item_scope' => 'SERVICE',
        'service_id' => $fx['serviceA']->id,
        'service_category_reference_id' => null,
        'calculation_type' => 'PERCENTAGE',
        'rate_value' => 20,
        'effective_from' => '2026-01-01',
        'effective_until' => null,
        'priority' => 0,
        'status' => 'ACTIVE',
        'notes' => null,
    ])->assertCreated();

    $this->actingAs($fx['user'])
        ->postJson('/api/v1/sales/transactions/'.$sale->id.'/discount-preview', [
            'discount_code' => 'save20',
        ])
        ->assertOk()
        ->assertJsonPath('discount.code', 'SAVE20')
        ->assertJsonPath('discount.gross_amount', 500)
        ->assertJsonPath('discount.discount_amount', 100)
        ->assertJsonPath('discount.net_amount', 400)
        ->assertJsonPath('allocation.items.0.net_line_total', 400)
        ->assertJsonPath('allocation.items.0.balance_amount', 400);

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$sale->id.'/finalize-checkout', [
        'idempotency_key' => 'p4-discount-save20-finalize',
        'discount_code' => 'SAVE20',
        'advance_allocations' => [],
        'amount_collected_now' => 400,
        'payment_method_reference_id' => $fx['cash']->id,
        'checkout_allocations' => [['sale_item_id' => $item->id, 'amount' => 400]],
        'allow_remaining_balance' => false,
    ])->assertOk()
        ->assertJsonPath('sale.status', 'PAID')
        ->assertJsonPath('sale.gross_amount', 500)
        ->assertJsonPath('sale.discount_amount', 100)
        ->assertJsonPath('sale.net_amount', 400)
        ->assertJsonPath('sale.outstanding_balance', 0);

    $sale->refresh();
    $item->refresh();

    expect((float) $sale->gross_amount)->toBe(500.0)
        ->and((float) $sale->discount_amount)->toBe(100.0)
        ->and((float) $sale->net_amount)->toBe(400.0)
        ->and($sale->discount_code_snapshot)->toBe('SAVE20')
        ->and((float) $item->line_total)->toBe(500.0)
        ->and((float) $item->discount_amount)->toBe(100.0)
        ->and((float) $item->net_line_total)->toBe(400.0)
        ->and((float) $item->balance_amount)->toBe(0.0)
        ->and((float) Payment::query()->where('sale_id', $sale->id)->where('payment_type', 'CHECKOUT')->value('amount'))->toBe(400.0);

    $this->actingAs($fx['user'])
        ->getJson('/api/v1/operations/bookings?view=COMPLETED')
        ->assertOk()
        ->assertJsonPath('bookings.0.payment_status', 'PAID')
        ->assertJsonPath('bookings.0.net_after_discount_total', 400);
});

it('discounted package keeps gross price history while receivable uses the net package price', function () {
    $fx = phase4Fixture();
    $sale = phase4Sale($this, $fx, [$fx['packageA']->id]);
    $item = $sale->items->first();

    $this->actingAs($fx['user'])->postJson('/api/v1/admin/discount-rules', [
        'rule_name' => 'Package Save 100',
        'discount_code' => 'PKG100',
        'branch_id' => null,
        'applies_to' => 'ALL',
        'customer_id' => null,
        'customer_type' => null,
        'item_scope' => 'PACKAGE',
        'service_id' => $fx['packageA']->id,
        'service_category_reference_id' => null,
        'calculation_type' => 'FIXED',
        'rate_value' => 100,
        'effective_from' => '2026-01-01',
        'effective_until' => null,
        'priority' => 0,
        'status' => 'ACTIVE',
        'notes' => null,
    ])->assertCreated();

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$sale->id.'/finalize-checkout', [
        'idempotency_key' => 'p4-discount-package-finalize',
        'discount_code' => 'PKG100',
        'advance_allocations' => [],
        'amount_collected_now' => 100,
        'payment_method_reference_id' => $fx['cash']->id,
        'checkout_allocations' => [['sale_item_id' => $item->id, 'amount' => 100]],
        'allow_remaining_balance' => true,
    ])->assertOk()
        ->assertJsonPath('sale.status', 'ON_ACCOUNT')
        ->assertJsonPath('sale.net_amount', 400)
        ->assertJsonPath('sale.outstanding_balance', 300);

    $item->refresh();
    $enrollment = \App\Models\PackageEnrollment::query()->findOrFail($item->package_enrollment_id);

    expect((float) $enrollment->package_gross_price_snapshot)->toBe(500.0)
        ->and((float) $enrollment->package_price_snapshot)->toBe(400.0)
        ->and((float) $item->balance_amount)->toBe(300.0);
});


it('uses historical lifetime value for discount eligibility and excludes the current sale', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-18 12:00:00', 'Asia/Manila'));

    try {
        $fx = phase4Fixture();

        $historicalSale = phase4Sale($this, $fx, [$fx['serviceB']->id]);
        $historicalItem = $historicalSale->items->first();

        $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$historicalSale->id.'/finalize-checkout', [
            'idempotency_key' => 'p40-historical-ltv-300',
            'advance_allocations' => [],
            'amount_collected_now' => 300,
            'payment_method_reference_id' => $fx['cash']->id,
            'checkout_allocations' => [['sale_item_id' => $historicalItem->id, 'amount' => 300]],
            'allow_remaining_balance' => false,
        ])->assertOk();

        $currentSale = phase4Sale($this, $fx, [$fx['serviceA']->id], 200);

        $baseRule = [
            'branch_id' => null,
            'applies_to' => 'ALL',
            'customer_id' => null,
            'customer_type' => null,
            'max_lifetime_value' => null,
            'last_visit_operator' => null,
            'last_visit_days_min' => null,
            'last_visit_days_max' => null,
            'item_scope' => 'SERVICE',
            'service_id' => $fx['serviceA']->id,
            'service_category_reference_id' => null,
            'calculation_type' => 'PERCENTAGE',
            'rate_value' => 10,
            'effective_from' => '2026-01-01',
            'effective_until' => null,
            'priority' => 0,
            'status' => 'ACTIVE',
            'notes' => null,
        ];

        $this->actingAs($fx['user'])->postJson('/api/v1/admin/discount-rules', [
            ...$baseRule,
            'rule_name' => 'Historical LTV 300',
            'discount_code' => 'LTV300',
            'min_lifetime_value' => 300,
        ])->assertCreated();

        $this->actingAs($fx['user'])
            ->postJson('/api/v1/sales/transactions/'.$currentSale->id.'/discount-preview', [
                'discount_code' => 'LTV300',
            ])
            ->assertOk()
            ->assertJsonPath('discount.discount_amount', 50)
            ->assertJsonPath('discount.net_amount', 450);

        $this->actingAs($fx['user'])->postJson('/api/v1/admin/discount-rules', [
            ...$baseRule,
            'rule_name' => 'Historical LTV 400',
            'discount_code' => 'LTV400',
            'min_lifetime_value' => 400,
        ])->assertCreated();

        $this->actingAs($fx['user'])
            ->postJson('/api/v1/sales/transactions/'.$currentSale->id.'/discount-preview', [
                'discount_code' => 'LTV400',
            ])
            ->assertStatus(422);
    } finally {
        Carbon::setTestNow();
    }
});

it('uses the previous visit for last visit discount eligibility instead of the current checkout visit', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-18 12:00:00', 'Asia/Manila'));

    try {
        $fx = phase4Fixture();

        $historicalSale = phase4Sale($this, $fx, [$fx['serviceB']->id]);
        $historicalItem = $historicalSale->items->first();

        $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$historicalSale->id.'/finalize-checkout', [
            'idempotency_key' => 'p40-historical-visit',
            'advance_allocations' => [],
            'amount_collected_now' => 300,
            'payment_method_reference_id' => $fx['cash']->id,
            'checkout_allocations' => [['sale_item_id' => $historicalItem->id, 'amount' => 300]],
            'allow_remaining_balance' => false,
        ])->assertOk();

        Visit::query()->whereKey($historicalSale->visit_id)->update([
            'visit_date' => '2026-07-01',
        ]);

        $currentSale = phase4Sale($this, $fx, [$fx['serviceA']->id]);

        $this->actingAs($fx['user'])->postJson('/api/v1/admin/discount-rules', [
            'rule_name' => 'Come Back 30',
            'discount_code' => 'COMEBACK30',
            'branch_id' => null,
            'applies_to' => 'ALL',
            'customer_id' => null,
            'customer_type' => null,
            'min_lifetime_value' => null,
            'max_lifetime_value' => null,
            'last_visit_operator' => 'MORE_THAN',
            'last_visit_days_min' => 30,
            'last_visit_days_max' => null,
            'item_scope' => 'SERVICE',
            'service_id' => $fx['serviceA']->id,
            'service_category_reference_id' => null,
            'calculation_type' => 'PERCENTAGE',
            'rate_value' => 10,
            'effective_from' => '2026-01-01',
            'effective_until' => null,
            'priority' => 0,
            'status' => 'ACTIVE',
            'notes' => null,
        ])->assertCreated();

        $this->actingAs($fx['user'])
            ->postJson('/api/v1/sales/transactions/'.$currentSale->id.'/discount-preview', [
                'discount_code' => 'COMEBACK30',
            ])
            ->assertOk()
            ->assertJsonPath('discount.discount_amount', 50)
            ->assertJsonPath('discount.net_amount', 450);
    } finally {
        Carbon::setTestNow();
    }
});


it('posts commission for the first rendered session of a newly sold package', function () {
    $fx = phase4Fixture();

    CommissionRule::query()->create([
        'business_id' => $fx['user']->business_id,
        'code' => 'CRL-PKG-FIRST-001',
        'rule_name' => 'First Package Session Commission',
        'branch_id' => null,
        'applies_to' => 'EMPLOYEE',
        'personnel_id' => $fx['personnel']->id,
        'position_reference_id' => null,
        'service_id' => $fx['packageA']->id,
        'service_category_reference_id' => null,
        'calculation_type' => 'FIXED',
        'rate_value' => 125,
        'basis' => 'STANDARD_SERVICE_PRICE',
        'effective_from' => '2026-01-01',
        'effective_until' => null,
        'priority' => 10,
        'status' => 'ACTIVE',
        'created_by' => $fx['user']->id,
        'updated_by' => $fx['user']->id,
    ]);

    $sale = phase4Sale($this, $fx, [$fx['packageA']->id]);
    $item = $sale->items->firstOrFail();

    expect($item->item_type)->toBe('PACKAGE');

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$sale->id.'/finalize-checkout', [
        'idempotency_key' => 'p59-package-first-session-commission',
        'advance_allocations' => [],
        'amount_collected_now' => 500,
        'payment_method_reference_id' => $fx['cash']->id,
        'checkout_allocations' => [['sale_item_id' => $item->id, 'amount' => 500]],
        'allow_remaining_balance' => false,
    ])->assertOk()->assertJsonPath('sale.status', 'PAID');

    $earning = CommissionEarning::query()->where('sale_id', $sale->id)->firstOrFail();

    expect($earning->visit_service_line_id)->toBe($item->visit_service_line_id)
        ->and($earning->personnel_id)->toBe($fx['personnel']->id)
        ->and($earning->service_id)->toBe($fx['packageA']->id)
        ->and($earning->basis)->toBe('RENDERED_PACKAGE_SESSION_VALUE')
        ->and((float) $earning->basis_amount)->toBe(100.0)
        ->and((float) $earning->commission_amount)->toBe(125.0)
        ->and($earning->status)->toBe('OPEN');
});

it('accepts split checkout payment methods when the split total exactly matches checkout payment', function () {
    $fx = phase4Fixture();
    $paymentGroup = ReferenceGroup::query()->where('business_id', $fx['user']->business_id)->where('code', 'PAYMENT_METHOD')->firstOrFail();
    $gcash = ReferenceValue::query()->create([
        'reference_group_id' => $paymentGroup->id,
        'code' => 'GCASH-P59',
        'display_value' => 'GCash',
        'sort_order' => 20,
        'active' => true,
    ]);
    mapCustomerPaymentMethod($fx['user'], $gcash, 'POS_CLEARING');

    $sale = phase4Sale($this, $fx, [$fx['serviceA']->id]);
    $item = $sale->items->firstOrFail();
    $proofToken = $this->supportingDocumentToken($fx['user'], 'sales', 'split-gcash-proof.pdf');

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$sale->id.'/finalize-checkout', [
        'idempotency_key' => 'p59-split-payment-ok',
        'attachment_tokens' => [$proofToken],
        'advance_allocations' => [],
        'amount_collected_now' => 500,
        'payment_splits' => [
            ['payment_method_reference_id' => $fx['cash']->id, 'amount' => 200, 'reference_no' => null],
            ['payment_method_reference_id' => $gcash->id, 'amount' => 300, 'reference_no' => 'GC-001'],
        ],
        'checkout_allocations' => [['sale_item_id' => $item->id, 'amount' => 500]],
        'allow_remaining_balance' => false,
    ])->assertOk()->assertJsonPath('sale.status', 'PAID');

    $payments = Payment::query()->where('sale_id', $sale->id)->where('payment_type', 'CHECKOUT')->orderBy('id')->get();
    expect($payments)->toHaveCount(2)
        ->and((float) $payments[0]->amount)->toBe(200.0)
        ->and((float) $payments[1]->amount)->toBe(300.0)
        ->and($payments[0]->payment_method_snapshot)->toBe('Cash')
        ->and($payments[1]->payment_method_snapshot)->toBe('GCash')
        ->and((float) PaymentApplication::query()->whereIn('payment_id', $payments->pluck('id'))->sum('amount'))->toBe(500.0);
});


it('requires checkout proof when any collected payment method is non-cash', function () {
    $fx = phase4Fixture();
    $paymentGroup = ReferenceGroup::query()->where('business_id', $fx['user']->business_id)->where('code', 'PAYMENT_METHOD')->firstOrFail();
    $gcash = ReferenceValue::query()->create([
        'reference_group_id' => $paymentGroup->id,
        'code' => 'GCASH-PROOF-REQUIRED',
        'display_value' => 'GCash',
        'sort_order' => 30,
        'active' => true,
    ]);
    mapCustomerPaymentMethod($fx['user'], $gcash, 'POS_CLEARING');

    $sale = phase4Sale($this, $fx, [$fx['serviceA']->id]);
    $item = $sale->items->firstOrFail();

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$sale->id.'/finalize-checkout', [
        'idempotency_key' => 'checkout-proof-required',
        'advance_allocations' => [],
        'amount_collected_now' => 500,
        'payment_splits' => [[
            'payment_method_reference_id' => $gcash->id,
            'amount' => 500,
            'reference_no' => 'GC-NO-PROOF',
        ]],
        'checkout_allocations' => [['sale_item_id' => $item->id, 'amount' => 500]],
        'allow_remaining_balance' => false,
    ])->assertStatus(422)
        ->assertJsonPath('message', 'Checkout payment proof is required for non-cash payments such as GCash, bank transfer, e-wallet or card. Cash payments are exempt.');

    expect($sale->fresh()->financial_finalized_at)->toBeNull();
});

it('treats a custom payment method mapped to Cash on Hand as cash for payment-proof control', function () {
    $fx = phase4Fixture();
    $paymentGroup = ReferenceGroup::query()->where('business_id', $fx['user']->business_id)->where('code', 'PAYMENT_METHOD')->firstOrFail();
    $counterCash = ReferenceValue::query()->create([
        'reference_group_id' => $paymentGroup->id,
        'code' => 'COUNTER-TENDER-PROOF-TEST',
        'display_value' => 'Counter Tender',
        'sort_order' => 35,
        'active' => true,
    ]);
    mapCustomerPaymentMethod($fx['user'], $counterCash, 'CASH_ON_HAND');

    $sale = phase4Sale($this, $fx, [$fx['serviceA']->id]);
    $item = $sale->items->firstOrFail();

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$sale->id.'/finalize-checkout', [
        'idempotency_key' => 'custom-cash-no-proof-required',
        'advance_allocations' => [],
        'amount_collected_now' => 500,
        'payment_splits' => [[
            'payment_method_reference_id' => $counterCash->id,
            'amount' => 500,
            'reference_no' => null,
        ]],
        'checkout_allocations' => [['sale_item_id' => $item->id, 'amount' => 500]],
        'allow_remaining_balance' => false,
    ])->assertOk()->assertJsonPath('sale.status', 'PAID');
});


it('rejects checkout when split payment methods do not equal the checkout payment total', function () {
    $fx = phase4Fixture();
    $paymentGroup = ReferenceGroup::query()->where('business_id', $fx['user']->business_id)->where('code', 'PAYMENT_METHOD')->firstOrFail();
    $gcash = ReferenceValue::query()->create([
        'reference_group_id' => $paymentGroup->id,
        'code' => 'GCASH-P59-MISMATCH',
        'display_value' => 'GCash',
        'sort_order' => 20,
        'active' => true,
    ]);
    mapCustomerPaymentMethod($fx['user'], $gcash, 'POS_CLEARING');

    $sale = phase4Sale($this, $fx, [$fx['serviceA']->id]);
    $item = $sale->items->firstOrFail();
    $proofToken = $this->supportingDocumentToken($fx['user'], 'sales', 'split-mismatch-proof.pdf');

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$sale->id.'/finalize-checkout', [
        'idempotency_key' => 'p59-split-payment-mismatch',
        'attachment_tokens' => [$proofToken],
        'advance_allocations' => [],
        'amount_collected_now' => 500,
        'payment_splits' => [
            ['payment_method_reference_id' => $fx['cash']->id, 'amount' => 200],
            ['payment_method_reference_id' => $gcash->id, 'amount' => 250],
        ],
        'checkout_allocations' => [['sale_item_id' => $item->id, 'amount' => 500]],
        'allow_remaining_balance' => false,
    ])->assertStatus(422)
        ->assertJsonPath('message', 'Split Payment total must equal the total Checkout Payment.');

    expect(Payment::query()->where('sale_id', $sale->id)->where('payment_type', 'CHECKOUT')->count())->toBe(0)
        ->and($sale->fresh()->financial_finalized_at)->toBeNull();
});

it('posts assigned staff commission for a finalized retail-only purchase', function () {
    $fx = phase4Fixture();

    CommissionRule::query()->create([
        'business_id' => $fx['user']->business_id,
        'code' => 'CRL-RETAIL-001',
        'rule_name' => 'Retail Sales 10%',
        'commission_scope' => 'RETAIL',
        'branch_id' => null,
        'applies_to' => 'EMPLOYEE',
        'personnel_id' => $fx['personnel']->id,
        'position_reference_id' => null,
        'service_id' => null,
        'service_category_reference_id' => null,
        'calculation_type' => 'PERCENTAGE',
        'rate_value' => 10,
        'basis' => 'NET_RETAIL_SALE',
        'effective_from' => '2026-01-01',
        'effective_until' => null,
        'priority' => 10,
        'status' => 'ACTIVE',
        'created_by' => $fx['user']->id,
        'updated_by' => $fx['user']->id,
    ]);

    $sale = phase4RetailSale($this, $fx, 'Retail Pomade', 2, 200);
    $item = $sale->items->firstOrFail();

    expect($item->item_type)->toBe('PRODUCT')
        ->and($item->assigned_personnel_id)->toBe($fx['personnel']->id)
        ->and((float) $item->line_total)->toBe(400.0)
        ->and(CommissionEarning::query()->where('sale_id', $sale->id)->count())->toBe(0);

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$sale->id.'/finalize-checkout', [
        'idempotency_key' => 'p60-retail-commission',
        'advance_allocations' => [],
        'amount_collected_now' => 400,
        'payment_method_reference_id' => $fx['cash']->id,
        'checkout_allocations' => [['sale_item_id' => $item->id, 'amount' => 400]],
        'allow_remaining_balance' => false,
    ])->assertOk()->assertJsonPath('sale.status', 'PAID');

    $earning = CommissionEarning::query()->where('sale_id', $sale->id)->firstOrFail();

    expect($earning->earning_type)->toBe('RETAIL')
        ->and($earning->visit_retail_line_id)->toBe($item->visit_retail_line_id)
        ->and($earning->visit_service_line_id)->toBeNull()
        ->and($earning->service_id)->toBeNull()
        ->and($earning->personnel_id)->toBe($fx['personnel']->id)
        ->and($earning->basis)->toBe('NET_RETAIL_SALE')
        ->and((float) $earning->basis_amount)->toBe(400.0)
        ->and((float) $earning->commission_amount)->toBe(40.0)
        ->and($earning->status)->toBe('OPEN')
        ->and(InventoryMovement::query()->where('sale_item_id', $item->id)->where('movement_type', 'RETAIL_SALE')->count())->toBe(1);
});



it('excludes correction refund due from effective dashboard collections while preserving the received payment', function () {
    $fx = phase4Fixture();
    $sale = phase4Sale($this, $fx, [$fx['serviceA']->id, $fx['serviceB']->id]);
    $items = $sale->items->values();

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$sale->id.'/finalize-checkout', [
        'idempotency_key' => 'p4-reporting-correction-1',
        'advance_allocations' => [],
        'amount_collected_now' => 800,
        'payment_method_reference_id' => $fx['cash']->id,
        'reference_no' => 'CHK-REPORT-001',
        'checkout_allocations' => [
            ['sale_item_id' => $items[0]->id, 'amount' => 500],
            ['sale_item_id' => $items[1]->id, 'amount' => 300],
        ],
        'allow_remaining_balance' => false,
    ])->assertOk()->assertJsonPath('sale.status', 'PAID');

    // Simulate the posted financial result of an approved post-checkout
    // Correction removing the second paid service. The immutable Payment remains
    // RECEIVED at 800, while 300 is now Refund Due and only 500 remains effective
    // collection against the valid transaction.
    $items[1]->update([
        'quantity' => 0,
        'unit_price' => 0,
        'line_total' => 0,
        'net_line_total' => 0,
        'effective_net_line_total' => 0,
        'balance_amount' => 0,
        'refund_due_amount' => 300,
        'correction_status' => 'REMOVED',
        'status' => 'CORRECTED_REMOVED',
    ]);
    $sale->update([
        'gross_amount' => 500,
        'net_amount' => 500,
        'effective_net_amount' => 500,
        'applied_amount' => 800,
        'outstanding_balance' => 0,
        'refund_due_amount' => 300,
        'refund_status' => 'DUE',
        'status' => 'PAID',
    ]);

    $report = app(SalesReportingService::class)->dashboard($fx['user'], [
        'from' => '2026-01-01',
        'to' => '2026-12-31',
        'branch_id' => $fx['user']->default_branch_id,
    ]);

    expect((float) Payment::query()->where('status', 'RECEIVED')->sum('amount'))->toBe(800.0)
        ->and((float) $report['summary']['payments_received'])->toBe(500.0)
        ->and((float) $report['payment_methods'][0]['amount'])->toBe(500.0)
        ->and($report)->toHaveKey('customer_class');
});


it('stacks multiple promotion codes sequentially and settles only the promoted net amount', function () {
    $fx = phase4Fixture();
    $sale = phase4Sale($this, $fx, [$fx['serviceA']->id]);
    $item = $sale->items->first();

    foreach ([['STACK20', 20], ['STACK10', 10]] as [$code, $rate]) {
        $this->actingAs($fx['user'])->postJson('/api/v1/admin/discount-rules', [
            'rule_name' => $code, 'discount_code' => $code, 'branch_id' => null, 'applies_to' => 'ALL',
            'customer_id' => null, 'customer_type' => null, 'item_scope' => 'SERVICE',
            'service_id' => $fx['serviceA']->id, 'service_category_reference_id' => null,
            'calculation_type' => 'PERCENTAGE', 'rate_value' => $rate, 'effective_from' => '2026-01-01',
            'effective_until' => null, 'priority' => 0, 'stackable' => true, 'status' => 'ACTIVE', 'notes' => null,
        ])->assertCreated();
    }

    $this->actingAs($fx['user'])
        ->postJson('/api/v1/sales/transactions/'.$sale->id.'/discount-preview', ['discount_codes' => ['STACK20', 'STACK10']])
        ->assertOk()
        ->assertJsonPath('discount.gross_amount', 500)
        ->assertJsonPath('discount.discount_amount', 140)
        ->assertJsonPath('discount.net_amount', 360);

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$sale->id.'/finalize-checkout', [
        'idempotency_key' => 'p4-stacked-promos', 'discount_codes' => ['STACK20', 'STACK10'],
        'advance_allocations' => [], 'amount_collected_now' => 360,
        'payment_method_reference_id' => $fx['cash']->id,
        'checkout_allocations' => [['sale_item_id' => $item->id, 'amount' => 360]],
        'allow_remaining_balance' => false,
    ])->assertOk()->assertJsonPath('sale.status', 'PAID')->assertJsonPath('sale.net_amount', 360);

    $item->refresh();
    expect((float) $item->discount_amount)->toBe(140.0)
        ->and($item->promotion_applications)->toHaveCount(2)
        ->and((float) $item->net_line_total)->toBe(360.0);
});

it('does not duplicate commission when a posted correction adds a completed service before corrected checkout', function () {
    $fx = phase4Fixture();

    CommissionRule::query()->create([
        'business_id' => $fx['user']->business_id,
        'code' => 'CRL-CORR-ADD-080',
        'rule_name' => 'Corrected Added Service 80',
        'commission_scope' => 'SERVICE',
        'branch_id' => null,
        'applies_to' => 'EMPLOYEE',
        'personnel_id' => $fx['personnel']->id,
        'position_reference_id' => null,
        'service_id' => $fx['serviceB']->id,
        'service_category_reference_id' => null,
        'calculation_type' => 'FIXED',
        'rate_value' => 80,
        'basis' => 'STANDARD_SERVICE_PRICE',
        'effective_from' => '2026-01-01',
        'effective_until' => null,
        'priority' => 100,
        'status' => 'ACTIVE',
        'created_by' => $fx['user']->id,
        'updated_by' => $fx['user']->id,
    ]);

    // Finalize the original transaction without serviceB. The approved correction
    // then adds serviceB as a completed corrected line and creates the required
    // +80 commission adjustment immediately.
    $sale = phase4Sale($this, $fx, [$fx['serviceA']->id]);
    $originalItem = $sale->items->firstOrFail();

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$sale->id.'/finalize-checkout', [
        'idempotency_key' => 'commission-correction-original-checkout',
        'advance_allocations' => [],
        'amount_collected_now' => 500,
        'payment_method_reference_id' => $fx['cash']->id,
        'checkout_allocations' => [['sale_item_id' => $originalItem->id, 'amount' => 500]],
        'allow_remaining_balance' => false,
    ])->assertOk()->assertJsonPath('sale.status', 'PAID');

    $request = $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$sale->id.'/action-requests', [
        'action_type' => 'CORRECTION',
        'reason_code' => 'WRONG_SERVICE',
        'remarks' => 'Add the service that was actually rendered.',
        'lines' => [[
            'line_action' => 'ADD',
            'item_kind' => 'SERVICE',
            'service_id' => $fx['serviceB']->id,
            'quantity' => 1,
            'assigned_personnel_id' => $fx['personnel']->id,
            'unit_price' => 300,
        ]],
    ])->assertCreated();

    $requestId = (int) $request->json('request.id');
    $this->actingAs($fx['user'])
        ->postJson('/api/v1/sales/transaction-action-requests/'.$requestId.'/approve', [
            'manager_remarks' => 'Approved corrected service line.',
        ])
        ->assertOk()
        ->assertJsonPath('request.status', 'POSTED');

    $newItem = SaleItem::query()
        ->where('sale_id', $sale->id)
        ->where('service_id', $fx['serviceB']->id)
        ->where('id', '<>', $originalItem->id)
        ->orderByDesc('id')
        ->firstOrFail();

    $beforeCheckout = CommissionEarning::query()
        ->where('sale_item_id', $newItem->id)
        ->where('personnel_id', $fx['personnel']->id)
        ->where('status', '<>', 'REVERSED')
        ->where(function ($query) {
            $query->whereNull('earning_type')->orWhere('earning_type', '<>', 'INCENTIVE');
        })
        ->get();

    expect($beforeCheckout)->toHaveCount(1)
        ->and($beforeCheckout->first()->earning_type)->toBe('ADJUSTMENT')
        ->and((float) $beforeCheckout->sum('commission_amount'))->toBe(80.0);

    // The corrected checkout must recognize that the commission economics are
    // already satisfied. It must not stack a second SERVICE earning on top.
    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$sale->id.'/finalize-checkout', [
        'idempotency_key' => 'commission-correction-followup-checkout',
        'advance_allocations' => [],
        'amount_collected_now' => 300,
        'payment_method_reference_id' => $fx['cash']->id,
        'checkout_allocations' => [['sale_item_id' => $newItem->id, 'amount' => 300]],
        'allow_remaining_balance' => false,
    ])->assertOk();

    $afterCheckout = CommissionEarning::query()
        ->where('sale_item_id', $newItem->id)
        ->where('personnel_id', $fx['personnel']->id)
        ->where('status', '<>', 'REVERSED')
        ->where(function ($query) {
            $query->whereNull('earning_type')->orWhere('earning_type', '<>', 'INCENTIVE');
        })
        ->get();

    expect($afterCheckout)->toHaveCount(1)
        ->and($afterCheckout->first()->earning_type)->toBe('ADJUSTMENT')
        ->and((float) $afterCheckout->sum('commission_amount'))->toBe(80.0);
});
