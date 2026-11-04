<?php

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Permission;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    $this->seed(\Database\Seeders\FoundationSeeder::class);
    $this->admin = foundationUser('ROLE-SA', [], 'basic');
});

test('all current permission modules have an explicit plan mapping', function () {
    $business = $this->admin->business;
    $business->forceFill(['license_token' => subscriptionTestCode($business), 'license_revision' => 1])->save();
    $modules = app(SubscriptionService::class)->snapshot($business)['modules'];
    expect(array_values(array_diff(Permission::query()->pluck('module')->unique()->all(), $modules)))->toBe([]);
});

test('plan matrix and direct api restrictions apply even to super admins', function (string $tier, bool $standard, bool $premium) {
    $business = $this->admin->business;
    $business->forceFill(['license_token' => subscriptionTestCode($business, $tier), 'license_revision' => 1])->save();
    $this->actingAs($this->admin)->getJson('/api/v1/operations/customers')->assertOk();
    foreach (['inventory-master' => $standard, 'payroll' => $standard, 'expenses' => $standard, 'general-ledger' => $premium, 'report-sales' => $premium] as $module => $allowed) {
        // Exercise the actual permission middleware without relying on page controller data.
        Route::middleware(['api', 'auth:sanctum', 'permission:'.$module.',VIEW'])
            ->get('/api/tier-probe/'.$module, fn () => response()->json(['allowed' => true]));
        $response = $this->getJson('/api/tier-probe/'.$module);
        if ($allowed) $response->assertOk();
        else $response->assertForbidden()->assertJsonPath('code', 'SUBSCRIPTION_UPGRADE_REQUIRED');
    }
})->with([
    ['basic', false, false], ['standard', true, false], ['premium', true, true],
]);

test('basic blocks real restricted reads writes and opening balances', function () {
    $this->actingAs($this->admin)->getJson('/api/v1/hr/payroll')->assertForbidden();
    $this->postJson('/api/v1/hr/payroll', [])->assertForbidden();
    $this->getJson('/api/v1/accounting/general-ledger')->assertForbidden();
    $this->getJson('/api/v1/admin/opening-balances')->assertForbidden();
    $this->getJson('/api/v1/admin/users')->assertOk();
});

test('a premium license does not grant missing employee permissions', function () {
    $business = $this->admin->business;
    $business->forceFill(['license_token' => subscriptionTestCode($business), 'license_revision' => 1])->save();
    $employee = foundationUser('ROLE-BAR');
    $this->actingAs($employee)->getJson('/api/v1/hr/payroll')->assertForbidden();
    $this->postJson('/api/v1/subscription/activate', ['activation_code' => subscriptionTestCode($business)])->assertForbidden();
});

test('a signed license upgrades access and activation is audited and idempotent', function () {
    $token = subscriptionTestCode($this->admin->business, 'standard', 2);
    $this->actingAs($this->admin)->postJson('/api/v1/subscription/activate', ['activation_code' => $token])
        ->assertOk()->assertJsonPath('subscription.tier', 'standard');
    $this->postJson('/api/v1/subscription/activate', ['activation_code' => $token])->assertOk();
    expect(AuditLog::query()->where('action', 'ACTIVATE_SUBSCRIPTION')->count())->toBe(1);
    $this->admin->unsetRelation('business');
    $this->getJson('/api/v1/bootstrap')->assertOk()->assertJsonPath('subscription.tier', 'standard');
    $this->getJson('/api/v1/accounting/general-ledger')->assertForbidden();
});

test('unsigned edited foreign and unsupported activation codes are rejected without changing the plan', function (string $kind) {
    $business = $this->admin->business;
    $overrides = match ($kind) {
        'foreign' => ['installation_id' => (string) \Illuminate\Support\Str::uuid()],
        'business' => ['business_code' => 'OTHER'],
        'tier' => ['tier' => 'unlimited'],
        'version' => ['version' => 2],
        'revision' => ['revision' => '2'],
        'expiry' => ['expires_at' => '2027-01-01'],
        default => [],
    };
    $token = subscriptionTestCode($business, 'premium', 2, $overrides,
        $kind === 'wrong-key' ? sodium_crypto_sign_keypair() : null);
    if ($kind === 'edited') {
        $parts = explode('.', $token);
        $parts[1] = rtrim(strtr(base64_encode(json_encode(['tier' => 'premium'])), '+/', '-_'), '=');
        $token = implode('.', $parts);
    }
    if ($kind === 'unsigned') $token = 'premium';
    $before = $business->license_token;
    $this->actingAs($this->admin)->postJson('/api/v1/subscription/activate', ['activation_code' => $token])->assertUnprocessable();
    expect($business->fresh()->license_token)->toBe($before);
})->with(['foreign', 'business', 'tier', 'version', 'revision', 'wrong-key', 'edited', 'unsigned', 'expiry']);

test('downgrade preserves records and rejects replaying an older premium license', function () {
    $business = $this->admin->business;
    $premium = subscriptionTestCode($business, 'premium', 2);
    $basic = subscriptionTestCode($business, 'basic', 3);
    $usersBefore = User::query()->count();
    $this->actingAs($this->admin)->postJson('/api/v1/subscription/activate', ['activation_code' => $premium])->assertOk();
    $this->postJson('/api/v1/subscription/activate', ['activation_code' => $basic])->assertOk();
    $this->postJson('/api/v1/subscription/activate', ['activation_code' => $premium])->assertUnprocessable();
    expect(User::query()->count())->toBe($usersBefore)
        ->and($business->fresh()->license_revision)->toBe(3);
    $this->admin->unsetRelation('business');
    $this->getJson('/api/v1/hr/payroll')->assertForbidden();
});

test('unactivated installations get Premium trial and corrupt licenses fail closed', function () {
    $business = $this->admin->business;
    $business->forceFill(['license_token' => null, 'license_revision' => 0])->save();
    expect(app(SubscriptionService::class)->snapshot($business)['status'])->toBe('trial')
        ->and($this->admin->hasPermission('payroll', 'VIEW'))->toBeTrue();
    $business->forceFill(['license_token' => 'invalid-token'])->save();
    expect(app(SubscriptionService::class)->snapshot($business)['status'])->toBe('invalid')
        ->and($this->admin->hasPermission('payroll', 'VIEW'))->toBeFalse();
});

test('business setup input cannot set its own plan', function () {
    $this->actingAs($this->admin)->postJson('/api/v1/subscription/activate', ['tier' => 'premium'])->assertUnprocessable();
    expect(app(SubscriptionService::class)->snapshot($this->admin->business)['tier'])->toBe('basic');
});
