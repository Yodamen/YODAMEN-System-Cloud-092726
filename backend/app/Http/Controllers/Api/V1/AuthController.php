<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ChangeAccessCodeRequest;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Models\User;
use App\Services\Security\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $username = $request->string('username')->toString();
        $accessCode = $request->string('access_code')->toString();

        $user = User::query()->with(['role.permissions', 'business', 'defaultBranch', 'personnel.compensationRule'])
            ->whereRaw('LOWER(username) = ?', [$username])
            ->first();

        if (! $user) {
            $this->audit->record(
                action: 'LOGIN_FAILED',
                module: 'authentication',
                entityType: User::class,
                entityId: $username,
                reason: 'Unknown username.',
            );

            throw ValidationException::withMessages([
                'username' => ['Invalid username or access code.'],
            ]);
        }

        if (! $user->isActive()) {
            return response()->json(['message' => 'This user account is inactive.'], 403);
        }

        if ($user->locked_until && $user->locked_until->isFuture()) {
            return response()->json([
                'message' => 'This account is temporarily locked. Try again later.',
                'locked_until' => $user->locked_until->toIso8601String(),
            ], 423);
        }

        if (! Hash::check($accessCode, $user->password)) {
            $attempts = $user->failed_attempts + 1;
            $lockedUntil = null;

            if ($attempts >= (int) config('yodamen.authentication.max_failed_attempts', 5)) {
                $lockedUntil = now()->addMinutes((int) config('yodamen.authentication.lock_minutes', 15));
            }

            $user->forceFill([
                'failed_attempts' => $attempts,
                'locked_until' => $lockedUntil,
            ])->save();

            $this->audit->record(
                action: 'LOGIN_FAILED',
                module: 'authentication',
                entityType: User::class,
                entityId: $user->code ?? $user->id,
                reason: 'Incorrect access code.',
                actor: $user,
                metadata: ['failed_attempts' => $attempts],
            );

            throw ValidationException::withMessages([
                'username' => ['Invalid username or access code.'],
            ]);
        }

        $user->forceFill([
            'failed_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
        ])->save();

        Auth::login($user);
        $request->session()->regenerate();

        $this->audit->record(
            action: 'LOGIN',
            module: 'authentication',
            entityType: User::class,
            entityId: $user->code ?? $user->id,
            reason: 'Successful login.',
            actor: $user,
        );

        return response()->json([
            'message' => 'Login successful.',
            'user' => $this->presentUser($user->fresh(['role.permissions', 'business', 'defaultBranch', 'personnel.compensationRule'])),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->presentUser($request->user()->load(['role.permissions', 'business', 'defaultBranch', 'personnel.compensationRule'])),
        ]);
    }

    public function changeAccessCode(ChangeAccessCodeRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $beforeForceReset = $user->force_reset;

        $user->forceFill([
            'password' => $request->string('access_code')->toString(),
            'force_reset' => false,
            'failed_attempts' => 0,
            'locked_until' => null,
        ])->save();

        $this->audit->record(
            action: 'CHANGE_OWN_ACCESS_CODE',
            module: 'authentication',
            entityType: User::class,
            entityId: $user->code ?? $user->id,
            before: ['force_reset' => $beforeForceReset],
            after: ['force_reset' => false],
            reason: 'User changed own access code.',
            actor: $user,
        );

        return response()->json([
            'message' => 'Access Code updated successfully.',
            'user' => $this->presentUser($user->fresh(['role.permissions', 'business', 'defaultBranch', 'personnel.compensationRule'])),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->audit->record(
            action: 'LOGOUT',
            module: 'authentication',
            entityType: User::class,
            entityId: $user->code ?? $user->id,
            reason: 'User logged out.',
            actor: $user,
        );

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out.']);
    }

    private function presentUser(User $user): array
    {
        return [
            'id' => $user->id,
            'code' => $user->code,
            'username' => $user->username,
            'display_name' => $user->display_name ?: $user->name,
            'email' => $user->email,
            'status' => $user->status,
            'force_reset' => $user->force_reset,
            'role' => $user->role ? [
                'code' => $user->role->code,
                'name' => $user->role->name,
                'is_super_admin' => $user->role->is_super_admin,
            ] : null,
            'business' => $user->business ? [
                'code' => $user->business->code,
                'name' => $user->business->name,
            ] : null,
            'default_branch' => $user->defaultBranch ? [
                'code' => $user->defaultBranch->code,
                'name' => $user->defaultBranch->name,
            ] : null,
            'personnel' => $user->personnel ? [
                'id' => $user->personnel->id,
                'code' => $user->personnel->code,
                'name' => $user->personnel->full_name,
                'compensation_rule' => $user->personnel->compensationRule?->code,
                'has_operational_compensation' => strtoupper((string) ($user->personnel->compensationRule?->code ?? 'NONE')) !== 'NONE',
                'paid_leave_entitled' => (bool) $user->personnel->paid_leave_entitled,
            ] : null,
            'permissions' => $user->permissionCodes(),
        ];
    }
}
