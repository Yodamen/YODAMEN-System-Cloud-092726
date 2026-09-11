<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once dirname(__DIR__, 2).'/Support/Foundation.php';

beforeEach(function () {
    $this->seed(\Database\Seeders\FoundationSeeder::class);
});

test('active user can sign in with username and access code', function () {
    $user = foundationUser('ROLE-SA', [
        'username' => 'admin',
        'force_reset' => true,
    ]);

    $this->withoutMiddleware(ValidateCsrfToken::class)
        ->withHeader('Origin', 'http://localhost:5173')
        ->postJson('/api/v1/auth/login', [
            'username' => 'ADMIN',
            'access_code' => '123456',
        ])
        ->assertOk()
        ->assertJsonPath('user.username', 'admin')
        ->assertJsonPath('user.force_reset', true)
        ->assertJsonPath('user.role.code', 'ROLE-SA');

    expect($user->fresh()->failed_attempts)->toBe(0)
        ->and($user->fresh()->last_login_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'LOGIN')->where('actor_user_id', $user->id)->exists())->toBeTrue();
});

test('five failed access codes lock the account for the configured window', function () {
    $user = foundationUser('ROLE-SA', ['username' => 'locked-user']);

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->withHeader('Origin', 'http://localhost:5173')
            ->postJson('/api/v1/auth/login', [
                'username' => 'locked-user',
                'access_code' => 'wrong-code',
            ])
            ->assertUnprocessable();
    }

    $user->refresh();

    expect($user->failed_attempts)->toBe(5)
        ->and($user->locked_until)->not->toBeNull()
        ->and($user->locked_until->isFuture())->toBeTrue();
});

test('authenticated user can change own access code and clears force reset', function () {
    $user = foundationUser('ROLE-SA', [
        'username' => 'reset-user',
        'force_reset' => true,
    ]);

    $this->actingAs($user)
        ->putJson('/api/v1/auth/access-code', [
            'access_code' => '654321',
            'access_code_confirmation' => '654321',
        ])
        ->assertOk()
        ->assertJsonPath('user.force_reset', false);

    $user->refresh();

    expect($user->force_reset)->toBeFalse()
        ->and(AuditLog::query()->where('action', 'CHANGE_OWN_ACCESS_CODE')->where('actor_user_id', $user->id)->exists())->toBeTrue();
});
