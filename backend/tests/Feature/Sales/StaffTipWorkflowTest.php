<?php

use App\Models\AccountingJournalEntry;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentApplication;
use App\Models\Personnel;
use App\Models\ReferenceGroup;
use App\Models\ReferenceValue;
use App\Models\Sale;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\StaffTip;
use App\Models\Visit;
use Database\Seeders\CheckoutSettlementPermissionSeeder;
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
    $this->seed(CheckoutSettlementPermissionSeeder::class);
});

function tipFixture(): array
{
    $user = foundationUser();
    $groups = ReferenceGroup::query()->with('values')->get()->keyBy('code');

    $cash = ReferenceValue::query()->create([
        'reference_group_id' => $groups['PAYMENT_METHOD']->id,
        'code' => 'CASH-TIP-TEST', 'display_value' => 'Cash Tip Test', 'sort_order' => 10, 'active' => true,
    ]);
    mapCustomerPaymentMethod($user, $cash, 'CASH_ON_HAND');

    $ewallet = ReferenceValue::query()->create([
        'reference_group_id' => $groups['PAYMENT_METHOD']->id,
        'code' => 'EWALLET-TIP-TEST', 'display_value' => 'GCash Tip Test', 'sort_order' => 20, 'active' => true,
    ]);
    mapCustomerPaymentMethod($user, $ewallet, 'CASH_IN_BANK');

    $service = Service::query()->create([
        'business_id' => $user->business_id,
        'code' => 'SRV-TIP-001', 'name' => 'Tip Test Haircut',
        'service_category_reference_id' => $groups['SERVICE_CATEGORY']->values->firstWhere('code', 'HAIRCUT')->id,
        'service_count_reference_id' => $groups['SERVICE_COUNT']->values->firstWhere('code', 'SINGLE_SESSION')->id,
        'number_of_sessions' => 1, 'duration_minutes' => 30,
        'status_reference_id' => $groups['SERVICE_STATUS']->values->firstWhere('code', 'ACTIVE')->id,
    ]);
    ServicePrice::query()->create([
        'business_id' => $user->business_id, 'service_id' => $service->id,
        'code' => 'PRC-TIP-001', 'scope' => 'BRANCH', 'branch_id' => $user->default_branch_id,
        'standard_price' => 500, 'effective_from' => '2026-01-01', 'status' => 'ACTIVE', 'created_by' => $user->id,
    ]);

    $personnel = Personnel::query()->create([
        'business_id' => $user->business_id, 'code' => 'EMP-TIP-001', 'first_name' => 'Tip', 'last_name' => 'Barber',
        'position_reference_id' => $groups['EMPLOYEE_POSITION']->values->firstWhere('code', 'BARBER')->id,
        'personnel_type_reference_id' => $groups['PERSONNEL_TYPE']->values->firstWhere('code', 'EMPLOYEE')->id,
        'compensation_rule_reference_id' => $groups['COMPENSATION_RULE']->values->firstWhere('code', 'HOURLY_PLUS_COMMISSION')->id,
        'hourly_rate' => 100, 'can_perform_services' => true, 'commission_eligible' => true, 'status' => 'ACTIVE',
    ]);

    $customer = Customer::query()->create([
        'business_id' => $user->business_id, 'code' => 'CUS-TIP-001', 'first_name' => 'Tip', 'last_name' => 'Customer',
        'mobile_number' => '09170001111', 'mobile_normalized' => '09170001111', 'birthday' => '1990-01-01',
        'registration_date' => '2026-09-26', 'status' => 'ACTIVE',
    ]);

    return compact('user', 'cash', 'ewallet', 'service', 'personnel', 'customer');
}

function createTipSale($test, array $fx): Sale
{
    $queue = $test->actingAs($fx['user'])->postJson('/api/v1/operations/queue/walk-ins', [
        'customer_id' => $fx['customer']->id,
        'customer_type' => 'NEW',
        'services' => [['service_id' => $fx['service']->id, 'assigned_personnel_id' => $fx['personnel']->id]],
        'retail_items' => [],
    ])->assertCreated();

    $visitResponse = $test->actingAs($fx['user'])
        ->postJson('/api/v1/operations/queue/'.$queue->json('queue_entry.id').'/start-servicing')
        ->assertOk();

    $visit = Visit::query()->findOrFail($visitResponse->json('visit.id'))->fresh('serviceLines');
    foreach ($visit->serviceLines as $line) {
        $test->actingAs($fx['user'])->postJson('/api/v1/operations/visit-services/'.$line->id.'/complete')->assertOk();
    }

    return Sale::query()->where('visit_id', $visit->id)->firstOrFail()->fresh('items');
}

function finalizeWithElectronicTip($test, array $fx): StaffTip
{
    $sale = createTipSale($test, $fx);
    $item = $sale->items->first();

    $proofToken = $test->supportingDocumentToken($fx['user'], 'sales', 'tip-gcash-proof.pdf');

    $test->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$sale->id.'/finalize-checkout', [
        'idempotency_key' => 'tip-checkout-'.$sale->id,
        'attachment_tokens' => [$proofToken],
        'advance_allocations' => [],
        'amount_collected_now' => 500,
        'payment_splits' => [[
            'payment_method_reference_id' => $fx['ewallet']->id,
            'amount' => 700,
            'reference_no' => 'GCASH-TIP-001',
        ]],
        'tip_amount' => 200,
        'tip_personnel_id' => $fx['personnel']->id,
        'tip_payment_method_reference_id' => $fx['ewallet']->id,
        'checkout_allocations' => [['sale_item_id' => $item->id, 'amount' => 500]],
        'allow_remaining_balance' => false,
    ])->assertOk()->assertJsonPath('sale.status', 'PAID');

    return StaffTip::query()->firstOrFail();
}

test('electronic tip is separated from sale revenue and carried as staff tips payable', function () {
    $fx = tipFixture();
    $tip = finalizeWithElectronicTip($this, $fx);

    $sale = Sale::query()->findOrFail($tip->sale_id);
    $checkoutPayment = Payment::query()->where('sale_id', $sale->id)->where('payment_type', 'CHECKOUT')->firstOrFail();
    $tipPayment = Payment::query()->where('sale_id', $sale->id)->where('payment_type', 'TIP')->firstOrFail();
    $tipApplication = PaymentApplication::query()->where('payment_id', $tipPayment->id)->where('target_type', 'STAFF_TIP')->firstOrFail();

    expect((float) $checkoutPayment->amount)->toBe(500.0)
        ->and((float) $tipPayment->amount)->toBe(200.0)
        ->and((float) $sale->total_paid)->toBe(500.0)
        ->and((float) $tip->amount)->toBe(200.0)
        ->and($tip->status)->toBe('PENDING')
        ->and($tip->personnel_id)->toBe($fx['personnel']->id);

    $tipReceiptJournal = AccountingJournalEntry::query()
        ->where('source_type', 'PAYMENT')->where('source_id', $tipPayment->id)->where('source_event', 'RECEIVE')
        ->with('lines')->firstOrFail();
    $tipApplicationJournal = AccountingJournalEntry::query()
        ->where('source_type', 'PAYMENT_APPLICATION')->where('source_id', $tipApplication->id)->where('source_event', 'APPLY')
        ->with('lines')->firstOrFail();

    expect((float) $tipReceiptJournal->lines->firstWhere('account_key', 'CASH_IN_BANK')->debit)->toBe(200.0)
        ->and((float) $tipReceiptJournal->lines->firstWhere('account_key', 'CUSTOMER_ADVANCES')->credit)->toBe(200.0)
        ->and((float) $tipApplicationJournal->lines->firstWhere('account_key', 'CUSTOMER_ADVANCES')->debit)->toBe(200.0)
        ->and((float) $tipApplicationJournal->lines->firstWhere('account_key', 'STAFF_TIPS_PAYABLE')->credit)->toBe(200.0);
});

test('cash tips are not tracked by checkout tip workflow', function () {
    $fx = tipFixture();
    $sale = createTipSale($this, $fx);
    $item = $sale->items->first();

    $this->actingAs($fx['user'])->postJson('/api/v1/sales/transactions/'.$sale->id.'/finalize-checkout', [
        'idempotency_key' => 'tip-cash-reject-'.$sale->id,
        'advance_allocations' => [],
        'amount_collected_now' => 500,
        'payment_splits' => [[
            'payment_method_reference_id' => $fx['cash']->id,
            'amount' => 700,
            'reference_no' => null,
        ]],
        'tip_amount' => 200,
        'tip_personnel_id' => $fx['personnel']->id,
        'tip_payment_method_reference_id' => $fx['cash']->id,
        'checkout_allocations' => [['sale_item_id' => $item->id, 'amount' => 500]],
        'allow_remaining_balance' => false,
    ])->assertStatus(422)
      ->assertJsonPath('message', 'Only tips received through a business bank, e-wallet, or card/POS clearing account are tracked. Cash tips are not recorded in YODAMEN.');

    expect(StaffTip::query()->count())->toBe(0)
        ->and(Payment::query()->where('payment_type', 'TIP')->count())->toBe(0);
});

test('pending electronic tip can be released from petty cash without touching cash on hand', function () {
    $fx = tipFixture();
    $tip = finalizeWithElectronicTip($this, $fx);
    $pettyCash = ChartOfAccount::query()->where('business_id', $fx['user']->business_id)->where('system_key', 'PETTY_CASH')->firstOrFail();

    $this->actingAs($fx['user'])->getJson('/api/v1/accounting/staff-tips?status=PENDING')
        ->assertOk()
        ->assertJsonPath('summary.pending_amount', 200)
        ->assertJsonPath('rows.0.id', $tip->id);

    $this->actingAs($fx['user'])->postJson('/api/v1/accounting/staff-tips/'.$tip->id.'/payout', [
        'payout_account_id' => $pettyCash->id,
        'payout_date' => '2026-09-26',
        'payout_reference' => 'PC-TIP-001',
    ])->assertOk()
      ->assertJsonPath('tip.status', 'PAID')
      ->assertJsonPath('tip.payout_account.id', $pettyCash->id);

    $tip->refresh();
    $journal = AccountingJournalEntry::query()
        ->where('source_type', 'STAFF_TIP')->where('source_id', $tip->id)->where('source_event', 'PAYOUT')
        ->with('lines')->firstOrFail();

    expect((float) $journal->lines->firstWhere('account_key', 'STAFF_TIPS_PAYABLE')->debit)->toBe(200.0)
        ->and((float) $journal->lines->firstWhere('account_key', 'PETTY_CASH')->credit)->toBe(200.0)
        ->and($journal->lines->firstWhere('account_key', 'CASH_ON_HAND'))->toBeNull();
});
