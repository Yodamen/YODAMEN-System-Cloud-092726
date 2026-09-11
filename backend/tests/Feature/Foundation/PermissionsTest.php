<?php

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    $this->seed(\Database\Seeders\FoundationSeeder::class);
});

test('server rejects an action when the role lacks the required permission', function () {
    $manager = foundationUser('ROLE-MGR');

    $this->actingAs($manager)
        ->getJson('/api/v1/admin/roles')
        ->assertForbidden();

    expect(AuditLog::query()->where('action', 'ACCESS_DENIED')->where('actor_user_id', $manager->id)->exists())->toBeTrue();
});

test('super admin can read permission management endpoints', function () {
    $superAdmin = foundationUser('ROLE-SA');

    $this->actingAs($superAdmin)
        ->getJson('/api/v1/admin/roles')
        ->assertOk()
        ->assertJsonFragment(['code' => 'ROLE-SA']);
});

test('super admin role permissions cannot be restricted', function () {
    $superAdmin = foundationUser('ROLE-SA');
    $role = Role::query()->where('code', 'ROLE-SA')->firstOrFail();

    $this->actingAs($superAdmin)
        ->putJson('/api/v1/admin/roles/'.$role->id.'/permissions', [
            'permissions' => ['dashboard.VIEW'],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Super Admin permissions cannot be restricted.');

    expect($role->fresh()->permissions()->count())->toBe(Permission::query()->count());
});

test('permission mutation is audited and automatically keeps view permission', function () {
    $superAdmin = foundationUser('ROLE-SA');
    $managerRole = Role::query()->where('code', 'ROLE-MGR')->firstOrFail();

    $this->actingAs($superAdmin)
        ->putJson('/api/v1/admin/roles/'.$managerRole->id.'/permissions', [
            'permissions' => ['users.EDIT'],
        ])
        ->assertOk();

    $codes = $managerRole->fresh()->permissions()->pluck('code')->all();

    expect($codes)->toContain('users.EDIT')
        ->and($codes)->toContain('users.VIEW')
        ->and(AuditLog::query()->where('action', 'UPDATE_ROLE_ACTION_PERMISSIONS')->where('entity_id', 'ROLE-MGR')->exists())->toBeTrue();
});
