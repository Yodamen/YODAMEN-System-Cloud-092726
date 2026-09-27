<?php

use App\Models\AccountingJournalEntry;
use App\Models\Customer;
use App\Models\OpeningCustomerReceivable;
use App\Models\PackageEnrollment;
use App\Models\PaymentApplication;
use App\Models\ReferenceGroup;
use App\Models\ReferenceValue;
use App\Models\Service;
use App\Models\ServiceCredit;
use App\Models\ServicePrice;
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
    $this->user = foundationUser('ROLE-SA');

    $groups = ReferenceGroup::query()->with('values')->get()->keyBy('code');
    $this->cash = ReferenceValue::query()->firstOrCreate(
        ['reference_group_id' => $groups['PAYMENT_METHOD']->id, 'code' => 'CASH'],
        ['display_value' => 'Cash', 'sort_order' => 10, 'is_system' => false, 'active' => true],
    );
    mapCustomerPaymentMethod($this->user, $this->cash);

    $this->package = Service::query()->create([
        'business_id' => $this->user->business_id,
        'code' => 'SRV-OPEN-PKG-001',
        'name' => 'Dermaflake 6 Sessions',
        'service_category_reference_id' => $groups['SERVICE_CATEGORY']->values->first()->id,
        'service_count_reference_id' => $groups['SERVICE_COUNT']->values->firstWhere('code', 'MULTI_SESSION')->id,
        'number_of_sessions' => 6,
        'duration_minutes' => 45,
        'status_reference_id' => $groups['SERVICE_STATUS']->values->firstWhere('code', 'ACTIVE')->id,
        'created_by' => $this->user->id,
        'updated_by' => $this->user->id,
    ]);

    ServicePrice::query()->create([
        'business_id' => $this->user->business_id,
        'service_id' => $this->package->id,
        'code' => 'PRC-OPEN-PKG-001',
        'scope' => 'BRANCH',
        'branch_id' => $this->user->default_branch_id,
        'standard_price' => 6000,
        'effective_from' => '2026-01-01',
        'status' => 'ACTIVE',
        'created_by' => $this->user->id,
    ]);

    $this->customer = Customer::query()->create([
        'business_id' => $this->user->business_id,
        'code' => 'CUS-OPEN-001',
        'first_name' => 'Opening',
        'last_name' => 'Customer',
        'mobile_number' => '09179998888',
        'mobile_normalized' => '09179998888',
        'registration_date' => '2026-09-25',
        'status' => 'ACTIVE',
    ]);
});

function postOpeningCustomerState($test): array
{
    $response = $test->actingAs($test->user)->postJson('/api/v1/admin/opening-balances', [
        'branch_id' => $test->user->default_branch_id,
        'cutover_date' => '2026-09-25',
        'cash_balances' => [],
        'inventory_balances' => [],
        'customer_receivables' => [[
            'customer_id' => $test->customer->id,
            'amount' => 1200,
            'reference' => 'Legacy service balance',
        ]],
        'active_packages' => [[
            'customer_id' => $test->customer->id,
            'service_id' => $test->package->id,
            'remaining_sessions' => 4,
            'remaining_service_value' => 4000,
            'outstanding_balance' => 3000,
        ]],
    ])->assertCreated();

    $enrollment = PackageEnrollment::query()->where('opening_balance_id', $response->json('opening_balance.id'))->firstOrFail();
    $credit = ServiceCredit::query()->where('package_enrollment_id', $enrollment->id)->firstOrFail();
    $packageReceivable = OpeningCustomerReceivable::query()->where('package_enrollment_id', $enrollment->id)->firstOrFail();

    return compact('response', 'enrollment', 'credit', 'packageReceivable');
}

test('opening setup establishes customer receivables and remaining active package sessions without recreating history', function () {
    $bootstrap = $this->actingAs($this->user)
        ->getJson('/api/v1/admin/opening-balances?branch_id='.$this->user->default_branch_id)
        ->assertOk();

    expect(collect($bootstrap->json('customers'))->pluck('id'))->toContain($this->customer->id)
        ->and(collect($bootstrap->json('package_services'))->pluck('id'))->toContain($this->package->id);

    $fx = postOpeningCustomerState($this);

    expect((float) $fx['response']->json('opening_balance.receivables_total'))->toBe(4200.0)
        ->and((float) $fx['response']->json('opening_balance.deferred_package_total'))->toBe(4000.0)
        ->and($fx['enrollment']->total_sessions)->toBe(6)
        ->and($fx['enrollment']->sessions_completed_before_activation)->toBe(2)
        ->and((float) $fx['enrollment']->package_price_snapshot)->toBe(6000.0)
        ->and($fx['credit']->sessions_purchased)->toBe(6)
        ->and($fx['credit']->sessions_used)->toBe(2)
        ->and($fx['credit']->sessions_remaining)->toBe(4)
        ->and(OpeningCustomerReceivable::query()->count())->toBe(2)
        ->and((float) $fx['packageReceivable']->remaining_balance)->toBe(3000.0);

    $journal = AccountingJournalEntry::query()->where('source_type', 'OPENING_BALANCE')->with(['lines.account'])->firstOrFail();
    expect((float) $journal->lines->sum('debit'))->toBe(4200.0)
        ->and((float) $journal->lines->sum('credit'))->toBe(4200.0)
        ->and((float) $journal->lines->firstWhere('account.system_key', 'AR_CUSTOMERS')->debit)->toBe(4200.0)
        ->and((float) $journal->lines->firstWhere('account.system_key', 'DEFERRED_PACKAGE_REVENUE')->credit)->toBe(4000.0)
        ->and((float) $journal->lines->firstWhere('account.system_key', 'OPENING_BALANCE_EQUITY')->credit)->toBe(200.0);

    $this->actingAs($this->user)
        ->getJson('/api/v1/accounting/accounts-receivable?branch_id='.$this->user->default_branch_id.'&as_of=2026-09-25')
        ->assertOk()
        ->assertJsonPath('summary.total', 4200)
        ->assertJsonCount(2, 'rows');

    $this->actingAs($this->user)
        ->getJson('/api/v1/service-balances')
        ->assertOk()
        ->assertJsonPath('balances.0.purchased', 6)
        ->assertJsonPath('balances.0.used', 2)
        ->assertJsonPath('balances.0.remaining', 4)
        ->assertJsonPath('balances.0.total_paid', 3000)
        ->assertJsonPath('balances.0.balance', 3000)
        ->assertJsonPath('balances.0.opening_balance', true);
});

test('a new booking receipt can settle an opening customer balance and keeps the collection tied to that booking', function () {
    $fx = postOpeningCustomerState($this);

    $response = $this->actingAs($this->user)->postJson('/api/v1/operations/bookings', [
        'customer_id' => $this->customer->id,
        'customer_type' => 'RETURNEE',
        'booking_date' => '2026-09-25',
        'booking_time' => '10:00',
        'booking_source' => 'RESERVATION',
        'services' => [[
            'service_id' => $this->package->id,
            'service_credit_id' => $fx['credit']->id,
            'quantity' => 1,
        ]],
        'retail_items' => [],
        'payment_collection' => [
            'collected' => true,
            'amount' => 1000,
            'advance_amount' => 0,
            'payment_method_reference_id' => $this->cash->id,
            'allocations' => [[
                'opening_receivable_id' => $fx['packageReceivable']->id,
                'amount' => 1000,
            ]],
        ],
    ])->assertCreated();

    $fx['packageReceivable']->refresh();
    expect((float) $fx['packageReceivable']->remaining_balance)->toBe(2000.0)
        ->and($fx['packageReceivable']->status)->toBe('OPEN');

    $application = PaymentApplication::query()->where('opening_customer_receivable_id', $fx['packageReceivable']->id)->firstOrFail();
    expect($application->target_type)->toBe('OPENING_AR')
        ->and((float) $application->amount)->toBe(1000.0)
        ->and($application->payment?->booking_id)->toBe($response->json('booking.id'));

    $paymentJournal = AccountingJournalEntry::query()->where('source_type', 'PAYMENT')->where('source_event', 'RECEIVE')->firstOrFail();
    $applicationJournal = AccountingJournalEntry::query()->where('source_type', 'PAYMENT_APPLICATION')->where('source_id', $application->id)->firstOrFail();
    expect($paymentJournal->branch_id)->toBe($this->user->default_branch_id)
        ->and($applicationJournal->branch_id)->toBe($this->user->default_branch_id);

    $this->actingAs($this->user)
        ->getJson('/api/v1/sales/on-account/customers/'.$this->customer->id)
        ->assertOk()
        ->assertJsonPath('outstanding_balance', 3200);
});

test('a fully paid opening package carries zero customer balance while preserving remaining service credits', function () {
    $this->actingAs($this->user)->postJson('/api/v1/admin/opening-balances', [
        'branch_id' => $this->user->default_branch_id,
        'cutover_date' => '2026-09-25',
        'active_packages' => [[
            'customer_id' => $this->customer->id,
            'service_id' => $this->package->id,
            'remaining_sessions' => 4,
            'remaining_service_value' => 4000,
            'outstanding_balance' => 0,
        ]],
    ])->assertCreated();

    expect(OpeningCustomerReceivable::query()->count())->toBe(0)
        ->and(ServiceCredit::query()->value('sessions_remaining'))->toBe(4);

    $this->actingAs($this->user)
        ->getJson('/api/v1/service-balances')
        ->assertOk()
        ->assertJsonPath('balances.0.total_paid', 6000)
        ->assertJsonPath('balances.0.balance', 0)
        ->assertJsonPath('balances.0.remaining', 4);
});
