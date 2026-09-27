<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\SupportingDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);
require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    $this->seed(\Database\Seeders\FoundationSeeder::class);
});

test('download permission is available in role management and automatically preserves view access', function () {
    $admin = foundationUser('ROLE-SA');
    $role = Role::query()->where('code', 'ROLE-MGR')->firstOrFail();

    $this->actingAs($admin)
        ->getJson('/api/v1/admin/roles')
        ->assertOk()
        ->assertJsonFragment([
            'code' => 'customers.DOWNLOAD',
            'module' => 'customers',
            'action' => 'DOWNLOAD',
        ])
        ->assertJsonFragment([
            'code' => 'bookings.DOWNLOAD',
            'module' => 'bookings',
            'action' => 'DOWNLOAD',
        ]);

    $this->actingAs($admin)
        ->putJson('/api/v1/admin/roles/'.$role->id.'/permissions', [
            'permissions' => ['customers.DOWNLOAD'],
        ])
        ->assertOk();

    $codes = $role->fresh()->permissions()->pluck('code')->all();
    expect($codes)->toContain('customers.DOWNLOAD')->and($codes)->toContain('customers.VIEW');
});

test('supporting document download is denied without the module download permission', function () {
    Storage::fake('local');
    $manager = foundationUser('ROLE-MGR');
    $permission = Permission::query()->where('code', 'expenses.DOWNLOAD')->firstOrFail();
    $manager->role->permissions()->detach($permission->id);

    Storage::disk('local')->put('supporting-documents/test-proof.pdf', 'proof');
    $document = SupportingDocument::query()->create([
        'business_id' => $manager->business_id,
        'module' => 'expenses',
        'entity_type' => 'EXPENSE',
        'entity_id' => 999,
        'purpose' => 'EXPENSE_SUPPORT',
        'upload_token' => (string) Str::uuid(),
        'state' => 'ATTACHED',
        'disk' => 'local',
        'path' => 'supporting-documents/test-proof.pdf',
        'original_name' => 'test-proof.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 5,
        'uploaded_by' => $manager->id,
        'attached_at' => now(),
    ]);

    $this->actingAs($manager)
        ->getJson('/api/v1/supporting-documents?entity_type=EXPENSE&entity_id=999')
        ->assertOk()
        ->assertJsonPath('documents.0.can_download', false);

    $this->actingAs($manager)
        ->get('/api/v1/supporting-documents/'.$document->id.'/download')
        ->assertNotFound();

    $manager->role->permissions()->attach($permission->id);

    $this->actingAs($manager)
        ->get('/api/v1/supporting-documents/'.$document->id.'/download')
        ->assertOk();
});
