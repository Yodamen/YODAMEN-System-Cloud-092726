<?php

use App\Models\Personnel;
use App\Models\ReferenceGroup;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    $this->seed(\Database\Seeders\FoundationSeeder::class);
    $this->seed(\Database\Seeders\MasterDataSeeder::class);
    $this->user = foundationUser('ROLE-SA');

    $groups = ReferenceGroup::query()->where('business_id', $this->user->business_id)->with('values')->get()->keyBy('code');
    $active = $groups['SERVICE_STATUS']->values->firstWhere('code', 'ACTIVE')->id;
    $single = $groups['SERVICE_COUNT']->values->firstWhere('code', 'SINGLE_SESSION')->id;
    $haircut = $groups['SERVICE_CATEGORY']->values->firstWhere('code', 'HAIRCUT')->id;
    $alaCarte = $groups['SERVICE_CATEGORY']->values->firstWhere('code', 'ALA_CARTE')->id;

    $this->serviceOne = Service::query()->create([
        'business_id'=>$this->user->business_id,'code'=>'SRV-MULTI-001','name'=>'Multi Scope Haircut',
        'service_category_reference_id'=>$haircut,'service_count_reference_id'=>$single,'number_of_sessions'=>1,
        'duration_minutes'=>30,'status_reference_id'=>$active,
    ]);
    $this->serviceTwo = Service::query()->create([
        'business_id'=>$this->user->business_id,'code'=>'SRV-MULTI-002','name'=>'Multi Scope Grooming',
        'service_category_reference_id'=>$alaCarte,'service_count_reference_id'=>$single,'number_of_sessions'=>1,
        'duration_minutes'=>30,'status_reference_id'=>$active,
    ]);

    $position = $groups['EMPLOYEE_POSITION']->values->firstWhere('code', 'BARBER')->id;
    $employment = $groups['EMPLOYMENT_TYPE']->values->firstWhere('code', 'REGULAR')->id;
    $personnelType = $groups['PERSONNEL_TYPE']->values->firstWhere('code', 'EMPLOYEE')->id;
    $compensation = $groups['COMPENSATION_RULE']->values->firstWhere('code', 'HOURLY_PLUS_COMMISSION')->id;

    $this->personOne = Personnel::query()->create([
        'business_id'=>$this->user->business_id,'code'=>'PER-MULTI-001','first_name'=>'Multi','last_name'=>'One',
        'position_reference_id'=>$position,'employment_type_reference_id'=>$employment,'personnel_type_reference_id'=>$personnelType,
        'compensation_rule_reference_id'=>$compensation,'hourly_rate'=>100,'can_perform_services'=>true,'commission_eligible'=>true,'status'=>'ACTIVE',
    ]);
    $this->personTwo = Personnel::query()->create([
        'business_id'=>$this->user->business_id,'code'=>'PER-MULTI-002','first_name'=>'Multi','last_name'=>'Two',
        'position_reference_id'=>$position,'employment_type_reference_id'=>$employment,'personnel_type_reference_id'=>$personnelType,
        'compensation_rule_reference_id'=>$compensation,'hourly_rate'=>100,'can_perform_services'=>true,'commission_eligible'=>true,'status'=>'ACTIVE',
    ]);

    $this->haircutCategory = $haircut;
    $this->alaCarteCategory = $alaCarte;
});

function multiCommissionPayload($test): array
{
    return [
        'rule_name'=>'Multi Commission','branch_id'=>null,'applies_to'=>'EMPLOYEE','commission_scope'=>'SERVICE',
        'personnel_ids'=>[$test->personOne->id,$test->personTwo->id],'position_reference_id'=>null,
        'service_ids'=>[$test->serviceOne->id,$test->serviceTwo->id],
        'service_category_reference_ids'=>[$test->haircutCategory,$test->alaCarteCategory],
        'retail_product_id'=>null,'retail_category'=>null,'calculation_type'=>'PERCENTAGE','rate_value'=>20,
        'effective_from'=>'2026-09-26','effective_until'=>null,'priority'=>0,'status'=>'ACTIVE','notes'=>null,
    ];
}

test('commission rules accept multiple personnel services and categories', function () {
    $this->actingAs($this->user)->postJson('/api/v1/admin/commission-rules', multiCommissionPayload($this))
        ->assertCreated()
        ->assertJsonCount(2, 'rule.personnel_ids')
        ->assertJsonCount(2, 'rule.service_ids')
        ->assertJsonCount(2, 'rule.service_category_reference_ids');
});

test('promotion rules accept personnel and multiple service scopes', function () {
    $this->actingAs($this->user)->postJson('/api/v1/admin/discount-rules', [
        'rule_name'=>'Multi Promotion','discount_code'=>'MULTI20','branch_id'=>null,'personnel_ids'=>[$this->personOne->id,$this->personTwo->id],
        'applies_to'=>'ALL','customer_id'=>null,'customer_type'=>null,'min_lifetime_value'=>null,'max_lifetime_value'=>null,
        'last_visit_operator'=>null,'last_visit_days_min'=>null,'last_visit_days_max'=>null,'item_scope'=>'SERVICE',
        'service_ids'=>[$this->serviceOne->id,$this->serviceTwo->id],
        'service_category_reference_ids'=>[$this->haircutCategory,$this->alaCarteCategory],
        'calculation_type'=>'PERCENTAGE','rate_value'=>20,'effective_from'=>'2026-09-26','effective_until'=>null,
        'priority'=>0,'stackable'=>true,'status'=>'ACTIVE','notes'=>null,
    ])->assertCreated()
      ->assertJsonCount(2, 'rule.personnel_ids')
      ->assertJsonCount(2, 'rule.service_ids')
      ->assertJsonCount(2, 'rule.service_category_reference_ids');
});

test('incentive rules accept multiple personnel services and categories', function () {
    $this->actingAs($this->user)->postJson('/api/v1/admin/incentive-rules', [
        'rule_name'=>'Multi Incentive','branch_id'=>null,'metric'=>'SERVICE_NET','target_scope'=>'PERSONNEL',
        'personnel_ids'=>[$this->personOne->id,$this->personTwo->id],
        'service_ids'=>[$this->serviceOne->id,$this->serviceTwo->id],
        'service_category_reference_ids'=>[$this->haircutCategory,$this->alaCarteCategory],
        'threshold_value'=>1000,'reward_type'=>'FIXED','reward_value'=>100,'effective_from'=>'2026-09-26','effective_until'=>null,
        'priority'=>0,'stackable'=>true,'status'=>'ACTIVE','notes'=>null,
    ])->assertCreated()
      ->assertJsonCount(2, 'rule.personnel_ids')
      ->assertJsonCount(2, 'rule.service_ids')
      ->assertJsonCount(2, 'rule.service_category_reference_ids');
});
