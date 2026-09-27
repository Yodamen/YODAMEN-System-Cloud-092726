<?php
use App\Models\AccountingJournalEntry;
use App\Models\Personnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
uses(RefreshDatabase::class);
require_once dirname(__DIR__,2).'/Support/Foundation.php';

beforeEach(function(){
    $this->seed(\Database\Seeders\FoundationSeeder::class); $this->seed(\Database\Seeders\MasterDataSeeder::class); $this->seed(\Database\Seeders\HrCompletionPermissionSeeder::class); $this->admin=foundationUser('ROLE-SA');
    $r=$this->actingAs($this->admin)->postJson('/api/v1/hr/personnel',['first_name'=>'Cash','last_name'=>'Barber','mobile_number'=>null,'email'=>null,'position_code'=>'BARBER','branch_id'=>null,'employment_type_code'=>'REGULAR','personnel_type_code'=>'EMPLOYEE','profit_sharing_eligible'=>false,'profit_sharing_percentage'=>0,'profit_sharing_effective_from'=>null,'profit_sharing_effective_until'=>null,'hourly_rate'=>100,'ot_entitled'=>false,'ot_multiplier'=>null,'compensation_rule_code'=>'HOURLY_PLUS_COMMISSION','guaranteed_daily_pay'=>0,'can_perform_services'=>true,'commission_eligible'=>true,'hire_date'=>null,'linked_user_id'=>null,'status'=>'ACTIVE','notes'=>null])->assertCreated();
    $this->personnel=Personnel::findOrFail($r->json('personnel.id'));
});

test('cash advance remains draft until approved then ledger drives outstanding balance',function(){
    $r=$this->actingAs($this->admin)->postJson('/api/v1/hr/cash-advances',['personnel_id'=>$this->personnel->id,'issued_date'=>'2026-09-18','original_amount'=>5000,'deduction_per_payroll'=>1000,'purpose'=>'Emergency'])->assertCreated()->assertJsonPath('cash_advance.status','DRAFT');
    $id=$r->json('cash_advance.id');
    $this->actingAs($this->admin)->postJson("/api/v1/hr/cash-advances/$id/approve")->assertOk()->assertJsonPath('cash_advance.outstanding_balance',5000);
    $this->actingAs($this->admin)->postJson("/api/v1/hr/cash-advances/$id/repayments",['amount'=>1200,'entry_date'=>'2026-09-18','notes'=>'Cash repayment'])->assertOk()->assertJsonPath('cash_advance.outstanding_balance',3800);
});


test('cash advance write off is permanent forgiveness and super admin only', function(){
    $r=$this->actingAs($this->admin)->postJson('/api/v1/hr/cash-advances',['personnel_id'=>$this->personnel->id,'issued_date'=>'2026-09-18','original_amount'=>3000,'deduction_per_payroll'=>800,'purpose'=>'Write-off control'])->assertCreated();
    $id=$r->json('cash_advance.id');
    $this->actingAs($this->admin)->postJson("/api/v1/hr/cash-advances/$id/approve")->assertOk();

    $manager=foundationUser('ROLE-MGR',['username'=>'ca.manager']);
    $this->actingAs($manager)->postJson("/api/v1/hr/cash-advances/$id/write-off",['reason'=>'Manager attempted forgiveness.'])
        ->assertStatus(422)
        ->assertJsonPath('message','Only a Super Admin can forgive or write off a cash advance balance. Use the per-payroll waiver when the deduction should only be skipped for one payroll run.');

    $this->actingAs($this->admin)->postJson("/api/v1/hr/cash-advances/$id/write-off",['reason'=>'Approved exceptional forgiveness.'])
        ->assertOk()
        ->assertJsonPath('cash_advance.status','CLOSED')
        ->assertJsonPath('cash_advance.outstanding_balance',0);

    $journal=AccountingJournalEntry::query()->where('source_type','EMPLOYEE_CASH_ADVANCE')->where('source_id',$id)->where('source_event','WRITE_OFF')->with('lines')->first();
    expect($journal)->not->toBeNull()
        ->and($journal->status)->toBe('POSTED')
        ->and((float)$journal->lines->sum('debit'))->toBe(3000.0)
        ->and((float)$journal->lines->sum('credit'))->toBe(3000.0)
        ->and($journal->lines->where('account_key','STAFF_CASH_ADVANCES')->first()?->account_code)->toBe('1102');
});


test('cash advance display status and filters distinguish fully paid and written off completion', function(){
    $paid=$this->actingAs($this->admin)->postJson('/api/v1/hr/cash-advances',[
        'personnel_id'=>$this->personnel->id,
        'issued_date'=>'2026-09-18',
        'original_amount'=>1000,
        'deduction_per_payroll'=>500,
        'purpose'=>'Fully paid status',
    ])->assertCreated();
    $paidId=$paid->json('cash_advance.id');
    $this->actingAs($this->admin)->postJson("/api/v1/hr/cash-advances/$paidId/approve")->assertOk();
    $this->actingAs($this->admin)->postJson("/api/v1/hr/cash-advances/$paidId/repayments",[
        'amount'=>1000,
        'entry_date'=>'2026-09-19',
        'notes'=>'Paid in full',
    ])->assertOk()->assertJsonPath('cash_advance.display_status','FULLY_PAID')->assertJsonPath('cash_advance.payment_total',1000);

    $written=$this->actingAs($this->admin)->postJson('/api/v1/hr/cash-advances',[
        'personnel_id'=>$this->personnel->id,
        'issued_date'=>'2026-09-18',
        'original_amount'=>1500,
        'deduction_per_payroll'=>500,
        'purpose'=>'Written off status',
    ])->assertCreated();
    $writtenId=$written->json('cash_advance.id');
    $this->actingAs($this->admin)->postJson("/api/v1/hr/cash-advances/$writtenId/approve")->assertOk();
    $this->actingAs($this->admin)->postJson("/api/v1/hr/cash-advances/$writtenId/write-off",[
        'reason'=>'Approved write-off test',
    ])->assertOk()->assertJsonPath('cash_advance.display_status','WRITTEN_OFF')->assertJsonPath('cash_advance.written_off_total',1500);

    $this->actingAs($this->admin)->getJson('/api/v1/hr/cash-advances?status=FULLY_PAID')
        ->assertOk()
        ->assertJsonCount(1,'rows')
        ->assertJsonPath('rows.0.id',$paidId)
        ->assertJsonPath('rows.0.display_status','FULLY_PAID');

    $this->actingAs($this->admin)->getJson('/api/v1/hr/cash-advances?status=WRITTEN_OFF')
        ->assertOk()
        ->assertJsonCount(1,'rows')
        ->assertJsonPath('rows.0.id',$writtenId)
        ->assertJsonPath('rows.0.display_status','WRITTEN_OFF');
});
