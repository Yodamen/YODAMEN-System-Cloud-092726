<?php

use App\Models\Business;
use App\Models\User;
use App\Services\Foundation\BrowserSetupService;
use App\Services\Foundation\CleanInstallationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function browserSetupData(): array
{
    return ['business_name' => 'Browser Client', 'business_code' => 'WEB', 'branch_name' => 'Main Branch',
        'username' => 'web_owner', 'display_name' => 'Web Owner', 'access_code' => 'PrivateTestCode123',
        'access_code_confirmation' => 'PrivateTestCode123'];
}
function browserSetupToken(): string { return str_repeat('a', 64); }
function enableBrowserSetup(): void { app(BrowserSetupService::class)->issue(browserSetupToken()); }

test('browser setup is disabled until explicitly enabled locally', function () {
    $this->getJson('/api/v1/setup')->assertOk()->assertJsonPath('requires_setup', false)->assertJsonMissingPath('token_hash');
    $this->withHeader('X-Yodamen-Setup-Token', browserSetupToken())->postJson('/api/v1/setup', browserSetupData())->assertConflict();
    expect(User::count())->toBe(0);
});

test('only a hash of the setup code is stored and wrong codes cannot create users', function () {
    enableBrowserSetup();
    expect(DB::table('installation_setups')->value('token_hash'))->toBe(hash('sha256', browserSetupToken()))->not->toBe(browserSetupToken());
    $this->getJson('/api/v1/setup')->assertOk()->assertJsonPath('available', true)->assertJsonMissingPath('token')->assertJsonMissingPath('token_hash');
    $this->withHeader('X-Yodamen-Setup-Token', str_repeat('b', 64))->postJson('/api/v1/setup', browserSetupData())->assertForbidden()->assertJsonPath('code', 'SETUP_TOKEN_INVALID');
    expect(User::count())->toBe(0)->and(Business::count())->toBe(0);
});

test('one valid setup creates and signs in the selected Super Admin and starts the trial', function () {
    enableBrowserSetup();
    $this->withHeader('X-Yodamen-Setup-Token', browserSetupToken())->postJson('/api/v1/setup', browserSetupData())
        ->assertCreated()->assertJsonPath('business_code', 'WEB')->assertJsonPath('subscription.status', 'trial')->assertJsonPath('subscription.trial_remaining', 50);
    $owner = User::sole();
    expect($owner->username)->toBe('web_owner')->and($owner->role->is_super_admin)->toBeTrue()
        ->and(Hash::check('PrivateTestCode123', $owner->password))->toBeTrue()
        ->and(DB::table('customers')->count())->toBe(0)->and(DB::table('bookings')->count())->toBe(0)->and(DB::table('sales')->count())->toBe(0);
    $this->assertAuthenticatedAs($owner);
    $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('user.username', 'web_owner');
    expect(DB::table('installation_setups')->value('token_hash'))->toBeNull()
        ->and(DB::table('installation_setups')->value('completed_at'))->not->toBeNull();
});

test('completed setup closes permanently and its code cannot be replayed or rotated', function () {
    enableBrowserSetup();
    $this->withHeader('X-Yodamen-Setup-Token', browserSetupToken())->postJson('/api/v1/setup', browserSetupData())->assertCreated();
    $this->getJson('/api/v1/setup')->assertJsonPath('requires_setup', false);
    $this->postJson('/api/v1/setup', browserSetupData())->assertConflict()->assertJsonPath('code', 'SETUP_CLOSED');
    expect(fn () => app(BrowserSetupService::class)->issue(str_repeat('c', 64)))->toThrow(DomainException::class);
    expect(User::count())->toBe(1)->and(Business::count())->toBe(1);
});

test('expired codes fail and a local refresh invalidates the old code', function () {
    enableBrowserSetup();
    $this->travel(25)->hours();
    $this->getJson('/api/v1/setup')->assertJsonPath('token_expired', true)->assertJsonPath('requires_setup', true)->assertJsonPath('available', false);
    $this->withHeader('X-Yodamen-Setup-Token', browserSetupToken())->postJson('/api/v1/setup', browserSetupData())->assertForbidden()->assertJsonPath('code', 'SETUP_TOKEN_EXPIRED');
    $new = str_repeat('c', 64);app(BrowserSetupService::class)->issue($new);
    $this->postJson('/api/v1/setup', browserSetupData())->assertForbidden()->assertJsonPath('code', 'SETUP_TOKEN_INVALID');
    $this->withHeader('X-Yodamen-Setup-Token', $new)->postJson('/api/v1/setup', browserSetupData())->assertCreated();
    $this->travelBack();
});

test('access code confirmation fails without consuming the setup token', function () {
    enableBrowserSetup();
    $this->withHeader('X-Yodamen-Setup-Token', browserSetupToken())->postJson('/api/v1/setup', [...browserSetupData(), 'access_code_confirmation' => 'Mismatch'])->assertUnprocessable();
    expect(User::count())->toBe(0)->and(DB::table('installation_setups')->value('token_hash'))->not->toBeNull();
});

test('settings failure rolls back both ownership and setup token consumption', function () {
    enableBrowserSetup();
    $fake = Mockery::mock(CleanInstallationService::class);
    $fake->shouldReceive('initialize')->once()->andReturnUsing(function () {
        Business::create(['code' => 'PARTIAL', 'name' => 'Failed setup', 'status' => 'ACTIVE']);
        throw new RuntimeException('Simulated failure');
    });
    app()->instance(CleanInstallationService::class, $fake);
    expect(fn () => app(BrowserSetupService::class)->complete(browserSetupToken(), browserSetupData()))->toThrow(RuntimeException::class);
    expect(Business::count())->toBe(0)->and(User::count())->toBe(0)
        ->and(DB::table('installation_setups')->value('token_hash'))->toBe(hash('sha256', browserSetupToken()));
});

test('existing installations never expose an open setup flow', function () {
    app(CleanInstallationService::class)->initialize(browserSetupData());
    $this->getJson('/api/v1/setup')->assertJsonPath('available', false)->assertJsonPath('requires_setup', false);
    $this->withHeader('X-Yodamen-Setup-Token', browserSetupToken())->postJson('/api/v1/setup', browserSetupData())->assertConflict();
    expect(User::count())->toBe(1);
});

test('setup code guessing is rate limited', function () {
    enableBrowserSetup();
    for ($i = 0; $i < 10; $i++) $this->withHeader('X-Yodamen-Setup-Token', str_repeat('b', 64))->postJson('/api/v1/setup', browserSetupData())->assertForbidden();
    $this->postJson('/api/v1/setup', browserSetupData())->assertStatus(429);
    expect(User::count())->toBe(0);
});
