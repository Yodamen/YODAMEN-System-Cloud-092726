<?php

use App\Models\Branch;
use App\Models\BusinessBrandingSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    $this->seed(\Database\Seeders\FoundationSeeder::class);
    $this->seed(\Database\Seeders\MasterDataSeeder::class);
    $this->admin = foundationUser('ROLE-SA');
});

test('super admin can maintain business setup and branches', function () {
    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/business-setup')
        ->assertOk()
        ->assertJsonPath('business.code', 'YDM');

    $this->actingAs($this->admin)
        ->putJson('/api/v1/admin/business-setup', [
            'name' => 'YODAMEN Grooming Lounge',
            'legal_name' => 'YODAMEN Grooming Lounge Inc.',
            'timezone' => 'Asia/Manila',
            'currency' => 'PHP',
        ])
        ->assertOk()
        ->assertJsonPath('business.name', 'YODAMEN Grooming Lounge');

    $branch = $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/business-setup/branches', [
            'code' => 'YDM-TST',
            'name' => 'Test Branch',
            'short_code' => 'TST',
            'address' => 'Test Address',
            'timezone' => 'Asia/Manila',
        ])
        ->assertCreated()
        ->assertJsonPath('branch.status', 'ACTIVE');

    $id = $branch->json('branch.id');
    expect(Branch::query()->findOrFail($id)->name)->toBe('Test Branch');

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/admin/business-setup/branches/{$id}/toggle")
        ->assertOk()
        ->assertJsonPath('branch.status', 'INACTIVE');
});

test('branding appearance settings are persisted and available to bootstrap and public login', function () {
    $payload = [
        'system_name' => 'YODAMEN Management System',
        'business_display_name' => 'YODAMEN',
        'subtitle' => 'Grooming Lounge',
        'logo_source' => 'URL',
        'logo_url' => 'https://example.com/logo.png',
        'font_family' => 'GEORGIA',
        'primary_color' => '#c8a64b',
        'accent_color' => '#d4af37',
        'sidebar_color' => '#101010',
        'login_label' => 'Operating System',
    ];

    $this->actingAs($this->admin)
        ->putJson('/api/v1/admin/branding', $payload)
        ->assertOk()
        ->assertJsonPath('branding.primary_color', '#c8a64b')
        ->assertJsonPath('branding.accent_color', '#d4af37')
        ->assertJsonPath('branding.sidebar_color', '#101010')
        ->assertJsonPath('branding.font_family', 'GEORGIA');

    $this->actingAs($this->admin)
        ->getJson('/api/v1/bootstrap')
        ->assertOk()
        ->assertJsonPath('branding.business_display_name', 'YODAMEN')
        ->assertJsonPath('branding.font_family', 'GEORGIA')
        ->assertJsonPath('branding.primary_color', '#c8a64b')
        ->assertJsonPath('branding.accent_color', '#d4af37')
        ->assertJsonPath('branding.sidebar_color', '#101010');

    $this->getJson('/api/v1/public/branding')
        ->assertOk()
        ->assertJsonPath('branding.logo_url', 'https://example.com/logo.png')
        ->assertJsonPath('branding.font_family', 'GEORGIA');
});

test('super admin can upload a logo image without a public storage symlink', function () {
    Storage::fake('local');

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    $file = UploadedFile::fake()->createWithContent('yodamen-logo.png', $png);

    $this->actingAs($this->admin)
        ->post('/api/v1/admin/branding/logo', ['logo' => $file], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('branding.logo_source', 'UPLOAD');

    $branding = BusinessBrandingSetting::query()->where('business_id', $this->admin->business_id)->firstOrFail();
    expect($branding->logo_path)->not->toBeNull();
    Storage::disk('local')->assertExists($branding->logo_path);

    $this->actingAs($this->admin)
        ->getJson('/api/v1/bootstrap')
        ->assertOk()
        ->assertJsonPath('branding.logo_source', 'UPLOAD')
        ->assertJsonPath('branding.logo_url', fn ($value) => is_string($value) && str_contains($value, '/api/v1/public/branding/logo/'));
});
