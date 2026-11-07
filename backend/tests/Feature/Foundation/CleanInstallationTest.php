<?php

use App\Models\Business;
use App\Models\User;
use App\Services\Foundation\CleanInstallationService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\CleanInstallationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function cleanSetupData(): array
{
    return ['business_name' => 'New Client Salon', 'business_code' => 'CLIENT', 'branch_name' => 'Main Branch',
        'username' => 'new_owner', 'display_name' => 'New Owner', 'access_code' => 'ChosenOnlyForTests123'];
}

test('clean initialization creates only configuration and one chosen administrator', function () {
    // The old default seeder credentials must never create another account.
    $business = app(CleanInstallationService::class)->initialize(cleanSetupData());
    $admin = User::query()->sole();
    expect($admin->username)->toBe('new_owner')
        ->and(Hash::check('ChosenOnlyForTests123', $admin->password))->toBeTrue()
        ->and($admin->role->is_super_admin)->toBeTrue()
        ->and($admin->force_reset)->toBeFalse()
        ->and($business->code)->toBe('CLIENT')
        ->and($business->name)->toBe('New Client Salon')
        ->and($business->license_installation_id)->not->toBeEmpty()
        ->and($business->license_revision)->toBe(0)
        ->and($business->trial_uses)->toBe(0);
    foreach (['customers', 'bookings', 'sales', 'personnel', 'retail_products', 'inventory_movements', 'payments', 'payroll_runs', 'expenses', 'accounting_journal_entries'] as $table) {
        if (\Illuminate\Support\Facades\Schema::hasTable($table)) expect(DB::table($table)->count())->toBe(0);
    }
    expect(DB::table('roles')->count())->toBeGreaterThan(0)
        ->and(DB::table('reference_values')->count())->toBeGreaterThan(0)
        ->and(DB::table('chart_of_accounts')->count())->toBeGreaterThan(0)
        ->and(app(SubscriptionService::class)->snapshot($business)['status'])->toBe('trial')
        ->and(app(SubscriptionService::class)->snapshot($business)['trial_remaining'])->toBe(50);
});

test('initialization refuses to reset an existing business account or trial', function () {
    $service = app(CleanInstallationService::class);
    $business = $service->initialize(cleanSetupData());
    $business->forceFill(['trial_uses' => 12])->save();
    $identity = $business->license_installation_id;
    expect(fn () => $service->initialize([...cleanSetupData(), 'access_code' => 'ReplacementNeverUsed']))->toThrow(DomainException::class);
    expect(User::query()->count())->toBe(1)
        ->and(Hash::check('ChosenOnlyForTests123', User::query()->sole()->password))->toBeTrue()
        ->and($business->fresh()->trial_uses)->toBe(12)
        ->and($business->fresh()->license_installation_id)->toBe($identity);
});

test('bad setup input creates no business or administrator', function () {
    expect(fn () => app(CleanInstallationService::class)->initialize([...cleanSetupData(), 'access_code' => 'x']))->toThrow(ValidationException::class);
    expect(Business::query()->count())->toBe(0)->and(User::query()->count())->toBe(0);
});

test('a failed settings seed rolls back all setup records', function () {
    $seeder = Mockery::mock(CleanInstallationSeeder::class);
    $seeder->shouldReceive('run')->once()->andReturnUsing(function () {
        Business::query()->create(['code' => 'YDM', 'name' => 'Incomplete setup', 'status' => 'ACTIVE']);
        throw new RuntimeException('Simulated seed failure');
    });
    app()->instance(CleanInstallationSeeder::class, $seeder);
    expect(fn () => app(CleanInstallationService::class)->initialize(cleanSetupData()))->toThrow(RuntimeException::class);
    expect(Business::query()->count())->toBe(0)->and(User::query()->count())->toBe(0);
});

test('interactive command checks confirmation before creating the first account', function () {
    $this->artisan('yodamen:initialize')
        ->expectsQuestion('Business name', 'New Client Salon')
        ->expectsQuestion('Business code', 'CLIENT')
        ->expectsQuestion('Main branch name', 'Main Branch')
        ->expectsQuestion('Super Admin username', 'new_owner')
        ->expectsQuestion('Super Admin display name', 'New Owner')
        ->expectsQuestion('Choose Super Admin access code', 'ChosenOnlyForTests123')
        ->expectsQuestion('Confirm access code', 'DifferentConfirmation')
        ->assertFailed();
    expect(User::query()->count())->toBe(0);
});

test('setup check is read only and recognizes a completed setup', function () {
    $this->artisan('yodamen:initialize --check')->assertFailed();
    app(CleanInstallationService::class)->initialize(cleanSetupData());
    $this->artisan('yodamen:initialize --check')->assertSuccessful();
    $this->artisan('yodamen:initialize')->assertFailed();
    expect(User::query()->count())->toBe(1);
});
