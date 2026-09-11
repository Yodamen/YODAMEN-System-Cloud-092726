<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('rerunning the initial super admin seeder preserves an existing changed access code', function () {
    config(['yodamen.authentication.minimum_access_code_length' => 6]);

    putenv('YODAMEN_SUPER_ADMIN_USERNAME=admin');
    putenv('YODAMEN_SUPER_ADMIN_ACCESS_CODE=Temporary123');

    $this->seed(\Database\Seeders\FoundationSeeder::class);
    $this->seed(\Database\Seeders\InitialSuperAdminSeeder::class);

    $user = User::query()->where('username', 'admin')->firstOrFail();
    $user->forceFill([
        'password' => 'ChangedAccess456',
        'force_reset' => false,
    ])->save();

    $this->seed(\Database\Seeders\InitialSuperAdminSeeder::class);

    $user->refresh();

    expect(Hash::check('ChangedAccess456', $user->password))->toBeTrue()
        ->and($user->force_reset)->toBeFalse();
});
