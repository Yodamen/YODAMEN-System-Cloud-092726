<?php

use App\Models\AuditLog;
use App\Models\Personnel;
use App\Models\ReferenceGroup;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    $this->seed(\Database\Seeders\FoundationSeeder::class);
    $this->seed(\Database\Seeders\MasterDataSeeder::class);
});

function adminPersonnel(array $overrides = []): Personnel
{
    $business = \App\Models\Business::query()->where('code', 'YDM')->firstOrFail();
    $position = ReferenceGroup::query()->where('code', 'EMPLOYEE_POSITION')->firstOrFail()->values()->where('code', 'BARBER')->firstOrFail();
    $personnelType = ReferenceGroup::query()->where('code', 'PERSONNEL_TYPE')->firstOrFail()->values()->where('code', 'EMPLOYEE')->firstOrFail();
    $employmentType = ReferenceGroup::query()->where('code', 'EMPLOYMENT_TYPE')->firstOrFail()->values()->where('code', 'REGULAR')->firstOrFail();
    $compRule = ReferenceGroup::query()->where('code', 'COMPENSATION_RULE')->firstOrFail()->values()->where('code', 'HOURLY_PLUS_COMMISSION')->firstOrFail();

    return Personnel::query()->create(array_merge([
        'business_id' => $business->id,
        'code' => 'EMP-TEST-'.str()->random(5),
        'first_name' => 'Test',
        'last_name' => 'Barber',
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
    ], $overrides));
}

test('super admin can create a user and link personnel', function () {
    $admin = foundationUser('ROLE-SA');
    $role = Role::query()->where('code', 'ROLE-BAR')->firstOrFail();
    $branch = \App\Models\Branch::query()->where('code', 'YDM-SJC')->firstOrFail();
    $personnel = adminPersonnel();

    $this->actingAs($admin)
        ->postJson('/api/v1/admin/users', [
            'username' => 'newbarber',
            'display_name' => 'New Barber',
            'email' => 'newbarber@example.test',
            'role_id' => $role->id,
            'default_branch_id' => $branch->id,
            'personnel_id' => $personnel->id,
            'status' => 'ACTIVE',
            'access_code' => '654321',
            'force_reset' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('user.username', 'newbarber')
        ->assertJsonPath('user.personnel.id', $personnel->id)
        ->assertJsonPath('user.role.code', 'ROLE-BAR');

    $user = User::query()->where('username', 'newbarber')->firstOrFail();
    expect($personnel->fresh()->linked_user_id)->toBe($user->id)
        ->and(AuditLog::query()->where('action', 'CREATE_USER')->where('entity_id', $user->code)->exists())->toBeTrue();
});

test('last active super admin cannot be deactivated', function () {
    $admin = foundationUser('ROLE-SA');

    $this->actingAs($admin)
        ->patchJson('/api/v1/admin/users/'.$admin->id.'/toggle')
        ->assertUnprocessable()
        ->assertJsonPath('message', 'The last active Super Admin cannot be deactivated or assigned to a non-Super Admin role.');

    expect($admin->fresh()->status)->toBe('ACTIVE');
});

test('administrator can reset user access code and require reset', function () {
    $admin = foundationUser('ROLE-SA');
    $barber = foundationUser('ROLE-BAR', ['force_reset' => false]);

    $this->actingAs($admin)
        ->postJson('/api/v1/admin/users/'.$barber->id.'/reset-access-code', [
            'access_code' => '777777',
            'force_reset' => true,
        ])
        ->assertOk()
        ->assertJsonPath('user.force_reset', true);

    expect(\Illuminate\Support\Facades\Hash::check('777777', $barber->fresh()->password))->toBeTrue();
});

test('super admin can create custom role then assign permissions', function () {
    $admin = foundationUser('ROLE-SA');

    $response = $this->actingAs($admin)
        ->postJson('/api/v1/admin/roles', [
            'code' => 'ROLE-HR-TEST',
            'name' => 'HR Test',
            'description' => 'HR testing role',
        ])
        ->assertCreated();

    $roleId = $response->json('role.id');

    $this->actingAs($admin)
        ->putJson('/api/v1/admin/roles/'.$roleId.'/permissions', [
            'permissions' => ['attendance.EDIT', 'employees.VIEW'],
        ])
        ->assertOk();

    $role = Role::query()->findOrFail($roleId);
    expect($role->permissions()->pluck('code')->all())
        ->toContain('attendance.EDIT')
        ->toContain('attendance.VIEW')
        ->toContain('employees.VIEW');
});

test('role with active users cannot be deactivated', function () {
    $admin = foundationUser('ROLE-SA');
    $manager = foundationUser('ROLE-MGR');
    $role = $manager->role;

    $this->actingAs($admin)
        ->patchJson('/api/v1/admin/roles/'.$role->id.'/toggle')
        ->assertUnprocessable();

    expect($role->fresh()->active)->toBeTrue();
});
