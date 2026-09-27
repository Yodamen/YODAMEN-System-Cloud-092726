<?php

use App\Models\AccountingJournalEntry;
use App\Models\CashRemittance;
use App\Models\ChartOfAccount;
use App\Services\Accounting\AccountingJournalService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    $this->seed(FoundationSeeder::class);
    $this->user = foundationUser('ROLE-SA');
    $this->manager = foundationUser('ROLE-MGR', ['display_name' => 'Cash Manager']);
    $this->cash = ChartOfAccount::query()->where('business_id', $this->user->business_id)->where('system_key', 'CASH_ON_HAND')->firstOrFail();
    $this->managerCash = ChartOfAccount::query()->where('business_id', $this->user->business_id)->where('system_key', 'CASH_WITH_MANAGER')->firstOrFail();
    $this->bank = ChartOfAccount::query()->where('business_id', $this->user->business_id)->where('system_key', 'CASH_IN_BANK')->firstOrFail();
});

function seedCashSaleForHandling($test, float $amount = 1000): void
{
    app(AccountingJournalService::class)->postEntry(
        $test->user->business_id,
        $test->user->default_branch_id,
        '2026-09-26',
        'PAYMENT',
        880001,
        'RECEIVE',
        'PAY-CASH-HANDLING',
        'Cash handling test receipt',
        [
            ['account_id' => $test->cash->id, 'debit' => $amount, 'credit' => 0],
            ['account_key' => 'CUSTOMER_ADVANCES', 'debit' => 0, 'credit' => $amount],
        ],
        $test->user->id,
    );
}

test('cash remittance moves physical cash from cash on hand to manager custody without touching revenue', function () {
    seedCashSaleForHandling($this);

    $response = $this->actingAs($this->user)->postJson('/api/v1/accounting/cash-remittances', [
        'branch_id' => $this->user->default_branch_id,
        'business_date' => '2026-09-26',
        'remittance_date' => '2026-09-26',
        'received_by_user_id' => $this->manager->id,
        'amount' => 600,
        'reference' => 'TURNOVER-001',
    ])->assertCreated();

    $remittance = CashRemittance::query()->findOrFail($response->json('remittance.id'));
    $journal = AccountingJournalEntry::query()
        ->where('source_type', 'CASH_REMITTANCE')->where('source_id', $remittance->id)->where('source_event', 'POST')
        ->with('lines.account')->firstOrFail();

    expect((float) $journal->lines->firstWhere('account_id', $this->managerCash->id)->debit)->toBe(600.0)
        ->and((float) $journal->lines->firstWhere('account_id', $this->cash->id)->credit)->toBe(600.0)
        ->and($journal->lines->where('account.account_type', 'INCOME')->count())->toBe(0);

    $this->actingAs($this->user)
        ->getJson('/api/v1/accounting/cash-remittances/day-summary?branch_id='.$this->user->default_branch_id.'&business_date=2026-09-26')
        ->assertOk()->assertJsonPath('summary.remaining_to_remit', 400);

    $remitterName = $this->user->display_name ?: $this->user->name ?: $this->user->username;
    $this->actingAs($this->user)->getJson('/api/v1/accounting/cash-remittances')
        ->assertOk()
        ->assertJsonPath('rows.0.remitted_by_user_id', $this->user->id)
        ->assertJsonPath('rows.0.remitted_by', $remitterName)
        ->assertJsonPath('rows.0.received_by', 'Cash Manager');

    $reversal = $this->actingAs($this->user)
        ->postJson('/api/v1/accounting/cash-remittances/'.$remittance->id.'/reverse', ['reason_reference_id' => $this->workflowReasonId('ACCIDENTAL_ENTRY'), 'reason' => 'Manager custody turnover was recorded in error.'])
        ->assertCreated()
        ->assertJsonPath('reversal_request.status', 'PENDING');

    expect($remittance->fresh()->status)->toBe('POSTED')
        ->and(AccountingJournalEntry::query()->where('source_type', 'CASH_REMITTANCE')->where('source_id', $remittance->id)->where('source_event', 'REVERSE')->exists())->toBeFalse();

    $this->actingAs($this->manager)
        ->postJson('/api/v1/accounting/cash-remittances/reversal-requests/'.$reversal->json('reversal_request.id').'/approve')
        ->assertOk()
        ->assertJsonPath('reversal_request.status', 'APPROVED');

    expect($remittance->fresh()->status)->toBe('REVERSED')
        ->and(AccountingJournalEntry::query()->where('source_type', 'CASH_REMITTANCE')->where('source_id', $remittance->id)->where('source_event', 'REVERSE')->exists())->toBeTrue();
});

test('bank deposit can consume a posted remittance and leave the undeposited balance with the manager', function () {
    seedCashSaleForHandling($this);

    $remittanceId = $this->actingAs($this->user)->postJson('/api/v1/accounting/cash-remittances', [
        'branch_id' => $this->user->default_branch_id,
        'business_date' => '2026-09-26',
        'remittance_date' => '2026-09-26',
        'received_by_user_id' => $this->manager->id,
        'amount' => 1000,
    ])->assertCreated()->json('remittance.id');

    $deposit = $this->actingAs($this->user)->postJson('/api/v1/accounting/cash-deposits', [
        'attachment_tokens' => [$this->supportingDocumentToken($this->user, 'cash-deposits')],
        'branch_id' => $this->user->default_branch_id,
        'business_date' => '2026-09-26',
        'deposit_date' => '2026-09-26',
        'deposit_source' => 'REMITTANCE',
        'cash_remittance_id' => $remittanceId,
        'bank_account_id' => $this->bank->id,
        'amount' => 400,
        'reference' => 'BANK-DEP-001',
    ])->assertCreated();

    $journal = AccountingJournalEntry::query()
        ->where('source_type', 'CASH_DEPOSIT')->where('source_id', $deposit->json('deposit.id'))->where('source_event', 'POST')
        ->with('lines.account')->firstOrFail();

    expect((float) $journal->lines->firstWhere('account_id', $this->bank->id)->debit)->toBe(400.0)
        ->and((float) $journal->lines->firstWhere('account_id', $this->managerCash->id)->credit)->toBe(400.0);

    $this->actingAs($this->user)->getJson('/api/v1/accounting/cash-remittances')
        ->assertOk()
        ->assertJsonPath('rows.0.deposited_amount', 400)
        ->assertJsonPath('rows.0.remaining_amount', 600)
        ->assertJsonPath('rows.0.handling_status', 'PARTIALLY_DEPOSITED');

    $depositorName = $this->user->display_name ?: $this->user->name ?: $this->user->username;
    $this->actingAs($this->user)->getJson('/api/v1/accounting/cash-deposits')
        ->assertOk()
        ->assertJsonPath('rows.0.deposited_by_user_id', $this->user->id)
        ->assertJsonPath('rows.0.deposited_by', $depositorName);
});

test('cash handling calendar color status progresses from pending to remitted to deposited', function () {
    seedCashSaleForHandling($this);

    $calendar = fn () => $this->actingAs($this->user)
        ->getJson('/api/v1/accounting/cash-handling/calendar?month=2026-09&branch_id='.$this->user->default_branch_id)
        ->assertOk();

    $dayIndex = 25;
    $calendar()->assertJsonPath('days.'.$dayIndex.'.business_date', '2026-09-26')->assertJsonPath('days.'.$dayIndex.'.status', 'PENDING');

    $remittanceId = $this->actingAs($this->user)->postJson('/api/v1/accounting/cash-remittances', [
        'branch_id' => $this->user->default_branch_id,
        'business_date' => '2026-09-26',
        'remittance_date' => '2026-09-26',
        'received_by_user_id' => $this->manager->id,
        'amount' => 1000,
    ])->assertCreated()->json('remittance.id');

    $calendar()->assertJsonPath('days.'.$dayIndex.'.status', 'REMITTED');

    $this->actingAs($this->user)->postJson('/api/v1/accounting/cash-deposits', [
        'attachment_tokens' => [$this->supportingDocumentToken($this->user, 'cash-deposits')],
        'branch_id' => $this->user->default_branch_id,
        'business_date' => '2026-09-26',
        'deposit_date' => '2026-09-26',
        'deposit_source' => 'REMITTANCE',
        'cash_remittance_id' => $remittanceId,
        'bank_account_id' => $this->bank->id,
        'amount' => 1000,
    ])->assertCreated();

    $calendar()->assertJsonPath('days.'.$dayIndex.'.status', 'DEPOSITED');
});
