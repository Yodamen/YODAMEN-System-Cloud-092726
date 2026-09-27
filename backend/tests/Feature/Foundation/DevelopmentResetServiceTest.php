<?php

use App\Models\Customer;
use App\Models\Personnel;
use App\Models\ReferenceGroup;
use App\Services\Development\DevelopmentResetService;
use App\Services\Foundation\CodeSequenceService;
use App\Services\Operations\CustomerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    $this->seed(\Database\Seeders\FoundationSeeder::class);
    $this->seed(\Database\Seeders\MasterDataSeeder::class);
});

function devResetCustomer(array $attributes = []): Customer
{
    $actor = foundationUser();
    $business = DB::table('businesses')->where('code', 'YDM')->first();

    return Customer::query()->create(array_merge([
        'business_id' => $business->id,
        'code' => 'CUS-DEVRESET-'.fake()->unique()->numerify('####'),
        'first_name' => 'Reset',
        'last_name' => 'Customer',
        'registration_date' => now()->toDateString(),
        'status' => 'ACTIVE',
        'is_test_customer' => false,
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
    ], $attributes));
}


function devResetPersonnel(): Personnel
{
    $business = \App\Models\Business::query()->where('code', 'YDM')->firstOrFail();
    $position = ReferenceGroup::query()->where('code', 'EMPLOYEE_POSITION')->firstOrFail()->values()->where('code', 'BARBER')->firstOrFail();
    $personnelType = ReferenceGroup::query()->where('code', 'PERSONNEL_TYPE')->firstOrFail()->values()->where('code', 'EMPLOYEE')->firstOrFail();
    $employmentType = ReferenceGroup::query()->where('code', 'EMPLOYMENT_TYPE')->firstOrFail()->values()->where('code', 'REGULAR')->firstOrFail();
    $compRule = ReferenceGroup::query()->where('code', 'COMPENSATION_RULE')->firstOrFail()->values()->where('code', 'HOURLY_PLUS_COMMISSION')->firstOrFail();

    return Personnel::query()->create([
        'business_id' => $business->id,
        'code' => 'EMP-DEVRESET-0001',
        'first_name' => 'Reset',
        'last_name' => 'Personnel',
        'position_reference_id' => $position->id,
        'employment_type_reference_id' => $employmentType->id,
        'personnel_type_reference_id' => $personnelType->id,
        'compensation_rule_reference_id' => $compRule->id,
        'hourly_rate' => 100,
        'ot_entitled' => false,
        'guaranteed_daily_pay' => 0,
        'can_perform_services' => true,
        'commission_eligible' => true,
        'status' => 'ACTIVE',
    ]);
}

test('full development reset clears transactions resets transactional ids and codes but preserves setup data and setup sequences', function () {
    $actor = foundationUser();
    $business = DB::table('businesses')->where('code', 'YDM')->first();
    $branch = DB::table('branches')->where('code', 'YDM-SJC')->first();

    $customer = devResetCustomer(['code' => 'CUS-DEVRESET-0001']);

    $sequence = app(CodeSequenceService::class);
    $firstBookingCode = $sequence->next('BOOKING:DEVRESET', 'BKG-DEVRESET-', 6, $business->id);
    $firstServiceCode = $sequence->next('service', 'SVC-', 6, $business->id);

    $bookingId = DB::table('bookings')->insertGetId([
        'business_id' => $business->id,
        'branch_id' => $branch->id,
        'code' => $firstBookingCode,
        'customer_id' => $customer->id,
        'customer_type' => 'NEW',
        'booking_date' => now()->toDateString(),
        'booking_time' => '10:00:00',
        'booking_source' => 'RESERVATION',
        'status' => 'BOOKED',
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $personnel = devResetPersonnel();

    $saleId = DB::table('sales')->insertGetId([
        'business_id' => $business->id,
        'branch_id' => $branch->id,
        'code' => 'SALE-DEVRESET-0001',
        'visit_id' => null,
        'booking_id' => null,
        'customer_id' => null,
        'sale_channel' => 'OPERATIONS',
        'sale_date' => now(),
        'gross_amount' => 100,
        'discount_amount' => 0,
        'net_amount' => 100,
        'advance_payments' => 0,
        'checkout_payments' => 100,
        'total_paid' => 100,
        'outstanding_balance' => 0,
        'status' => 'PAID',
        'financial_finalized_at' => now(),
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $saleItemId = DB::table('sale_items')->insertGetId([
        'sale_id' => $saleId,
        'code' => 'SALEITEM-DEVRESET-0001',
        'source_key' => 'DEVRESET-RESTRICT-FK-0001',
        'visit_service_line_id' => null,
        'visit_retail_line_id' => null,
        'service_id' => null,
        'assigned_personnel_id' => $personnel?->id,
        'assigned_personnel_name_snapshot' => $personnel?->full_name,
        'item_type' => 'SERVICE',
        'item_name_snapshot' => 'DEVRESET FK Test',
        'quantity' => 1,
        'unit_price' => 100,
        'line_total' => 100,
        'status' => 'PAID',
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $transactionActionRequestId = DB::table('transaction_action_requests')->insertGetId([
        'business_id' => $business->id,
        'branch_id' => $branch->id,
        'sale_id' => $saleId,
        'code' => 'TAR-DEVRESET-000001',
        'action_type' => 'CORRECTION',
        'status' => 'REJECTED',
        'reason_code' => 'OTHER',
        'reason_label' => 'Other',
        'remarks' => 'DEVRESET retained-row regression guard',
        'original_snapshot' => json_encode(['sale_id' => $saleId]),
        'proposed_snapshot' => json_encode(['lines' => []]),
        'impact_preview' => json_encode(['action_type' => 'CORRECTION']),
        'sale_version_hash' => str_repeat('a', 64),
        'requested_refund_amount' => 0,
        'requested_by' => $actor->id,
        'requested_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('transaction_action_request_lines')->insert([
        'transaction_action_request_id' => $transactionActionRequestId,
        'sale_item_id' => $saleItemId,
        'line_action' => 'EDIT',
        'original_snapshot' => json_encode(['sale_item_id' => $saleItemId]),
        'proposed_snapshot' => json_encode(['quantity' => 1]),
        'refund_quantity' => 0,
        'refund_amount' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('commission_earnings')->insert([
        'business_id' => $business->id,
        'code' => 'COM-DEVRESET-0001',
        'sale_id' => $saleId,
        'sale_item_id' => $saleItemId,
        'visit_id' => null,
        'visit_service_line_id' => null,
        'visit_retail_line_id' => null,
        'earning_type' => 'SERVICE',
        'booking_id' => null,
        'customer_id' => null,
        'service_id' => null,
        'personnel_id' => $personnel->id,
        'commission_rule_id' => null,
        'work_date' => now()->toDateString(),
        'service_name_snapshot' => 'DEVRESET FK Test',
        'personnel_name_snapshot' => $personnel->full_name,
        'basis' => 'STANDARD_SERVICE_PRICE',
        'basis_amount' => 100,
        'calculation_type' => 'FIXED',
        'rate_value' => 10,
        'commission_amount' => 10,
        'status' => 'OPEN',
        'created_by' => $actor->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('audit_logs')->insert([
        'actor_user_id' => $actor->id,
        'action' => 'TEST',
        'module' => 'development-reset',
        'created_at' => now(),
    ]);

    $serviceCount = DB::table('services')->count();
    $personnelCount = DB::table('personnel')->count();
    $referenceCount = DB::table('reference_values')->count();
    $userCount = DB::table('users')->count();

    $deleted = app(DevelopmentResetService::class)->execute();

    $nextCustomerId = DB::table('customers')->insertGetId([
        'business_id' => $business->id,
        'code' => 'CUS-AFTER-RESET-000001',
        'first_name' => 'After',
        'last_name' => 'Reset',
        'registration_date' => now()->toDateString(),
        'status' => 'ACTIVE',
        'is_test_customer' => false,
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $nextBookingId = DB::table('bookings')->insertGetId([
        'business_id' => $business->id,
        'branch_id' => $branch->id,
        'code' => 'BKG-AFTER-RESET-000001',
        'customer_id' => $nextCustomerId,
        'customer_type' => 'NEW',
        'booking_date' => now()->toDateString(),
        'booking_time' => '10:00:00',
        'booking_source' => 'RESERVATION',
        'status' => 'BOOKED',
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($bookingId)->toBe(1)
        ->and($deleted['customers'])->toBe(1)
        ->and($deleted['bookings'])->toBe(1)
        ->and($deleted['commission_earnings'])->toBe(1)
        ->and($deleted['sale_items'])->toBe(1)
        ->and($deleted['transaction_action_request_lines'])->toBe(1)
        ->and($deleted['transaction_action_requests'])->toBe(1)
        ->and(DB::table('commission_earnings')->count())->toBe(0)
        ->and(DB::table('sale_items')->count())->toBe(0)
        ->and(DB::table('transaction_action_request_lines')->count())->toBe(0)
        ->and(DB::table('transaction_action_requests')->count())->toBe(0)
        ->and(DB::table('audit_logs')->count())->toBe(0)
        ->and(DB::table('services')->count())->toBe($serviceCount)
        ->and(DB::table('personnel')->count())->toBe($personnelCount)
        ->and(DB::table('reference_values')->count())->toBe($referenceCount)
        ->and(DB::table('users')->count())->toBe($userCount)
        ->and($nextCustomerId)->toBe(1)
        ->and($nextBookingId)->toBe(1)
        ->and($sequence->next('BOOKING:DEVRESET', 'BKG-DEVRESET-', 6, $business->id))->toBe($firstBookingCode)
        ->and($sequence->next('service', 'SVC-', 6, $business->id))->not->toBe($firstServiceCode);
});

test('full development reset preview reports target counts without deleting rows', function () {
    devResetCustomer(['code' => 'CUS-DEVRESET-0002']);

    $preview = app(DevelopmentResetService::class)->preview();

    expect($preview['customers'])->toBe(1)
        ->and(DB::table('customers')->count())->toBe(1);
});

test('test-only reset deletes marked test customers and linked bookings while preserving real customers', function () {
    $actor = foundationUser();
    $business = DB::table('businesses')->where('code', 'YDM')->first();
    $branch = DB::table('branches')->where('code', 'YDM-SJC')->first();

    $testCustomer = devResetCustomer([
        'code' => 'CUS-TEST-0001',
        'first_name' => 'Test',
        'last_name' => 'Only',
        'is_test_customer' => true,
    ]);

    $realCustomer = devResetCustomer([
        'code' => 'CUS-REAL-0001',
        'first_name' => 'Real',
        'last_name' => 'Customer',
        'is_test_customer' => false,
    ]);

    foreach ([
        [$testCustomer->id, 'BKG-TEST-0001'],
        [$realCustomer->id, 'BKG-REAL-0001'],
    ] as [$customerId, $bookingCode]) {
        DB::table('bookings')->insert([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'code' => $bookingCode,
            'customer_id' => $customerId,
            'customer_type' => 'NEW',
            'booking_date' => now()->toDateString(),
            'booking_time' => '10:00:00',
            'booking_source' => 'RESERVATION',
            'status' => 'BOOKED',
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    DB::table('audit_logs')->insert([
        'actor_user_id' => $actor->id,
        'action' => 'TEST',
        'module' => 'development-reset-test',
        'created_at' => now(),
    ]);

    $preview = app(DevelopmentResetService::class)->previewTest();

    expect($preview['customers'])->toBe(1)
        ->and($preview['bookings'])->toBe(1);

    $deleted = app(DevelopmentResetService::class)->executeTest();

    expect($deleted['customers'])->toBe(1)
        ->and($deleted['bookings'])->toBe(1)
        ->and(DB::table('customers')->where('id', $testCustomer->id)->exists())->toBeFalse()
        ->and(DB::table('bookings')->where('code', 'BKG-TEST-0001')->exists())->toBeFalse()
        ->and(DB::table('customers')->where('id', $realCustomer->id)->exists())->toBeTrue()
        ->and(DB::table('bookings')->where('code', 'BKG-REAL-0001')->exists())->toBeTrue()
        ->and(DB::table('audit_logs')->count())->toBe(1);
});

test('only super admin can mark a newly created customer as test data', function () {
    $customers = app(CustomerService::class);
    $manager = foundationUser('ROLE-MGR');

    expect(fn () => $customers->save($manager, [
        'first_name' => 'Blocked',
        'last_name' => 'Test',
        'is_test_customer' => true,
    ]))->toThrow(\DomainException::class, 'Only Super Admin can mark a customer as Test Customer.');

    $superAdmin = foundationUser('ROLE-SA');
    $customer = $customers->save($superAdmin, [
        'first_name' => 'Allowed',
        'last_name' => 'Test',
        'is_test_customer' => true,
    ]);

    expect($customer->is_test_customer)->toBeTrue();
});
