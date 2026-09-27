<?php

namespace App\Services\Security;

use App\Models\Branch;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use App\Services\Foundation\CodeSequenceService;
use DomainException;
use Illuminate\Support\Facades\DB;

class UserAdministrationService
{
    public function __construct(
        private readonly CodeSequenceService $codes,
        private readonly AuditService $audit,
    ) {
    }

    public function save(User $actor, array $data, ?User $user = null): User
    {
        if ($user && $user->business_id !== $actor->business_id) {
            abort(404);
        }

        return DB::transaction(function () use ($actor, $data, $user) {
            $role = Role::query()
                ->whereKey($data['role_id'])
                ->where('business_id', $actor->business_id)
                ->where('active', true)
                ->first();

            if (! $role) {
                throw new DomainException('Selected role is invalid or inactive.');
            }

            $branch = null;
            if (! empty($data['default_branch_id'])) {
                $branch = Branch::query()
                    ->whereKey($data['default_branch_id'])
                    ->where('business_id', $actor->business_id)
                    ->where('status', 'ACTIVE')
                    ->first();

                if (! $branch) {
                    throw new DomainException('Selected branch is invalid or inactive.');
                }
            }

            $personnel = null;
            if (! empty($data['personnel_id'])) {
                $personnel = Personnel::query()
                    ->whereKey($data['personnel_id'])
                    ->where('business_id', $actor->business_id)
                    ->lockForUpdate()
                    ->first();

                if (! $personnel) {
                    throw new DomainException('Selected personnel record is invalid.');
                }

                if ($personnel->linked_user_id && $personnel->linked_user_id !== $user?->id) {
                    throw new DomainException('Selected personnel record is already linked to another user.');
                }
            }

            if ($user) {
                $this->guardLastSuperAdmin($user, $role, strtoupper($data['status']));
            }

            $before = $user ? $this->snapshot($user->loadMissing(['role', 'defaultBranch', 'personnel'])) : null;

            $attributes = [
                'business_id' => $actor->business_id,
                'default_branch_id' => $branch?->id,
                'role_id' => $role->id,
                'username' => strtolower(trim($data['username'])),
                'name' => trim($data['display_name']),
                'display_name' => trim($data['display_name']),
                'email' => trim((string) ($data['email'] ?? '')) ?: null,
                'status' => strtoupper($data['status']),
                'force_reset' => array_key_exists('force_reset', $data) ? (bool) $data['force_reset'] : true,
            ];

            if (! empty($data['access_code'])) {
                $attributes['password'] = $data['access_code'];
                $attributes['failed_attempts'] = 0;
                $attributes['locked_until'] = null;
            }

            if ($user) {
                $user->update($attributes);
            } else {
                $attributes['code'] = $this->nextAvailableUserCode($actor->business_id);
                $user = User::query()->create($attributes);
            }

            Personnel::query()
                ->where('business_id', $actor->business_id)
                ->where('linked_user_id', $user->id)
                ->when($personnel, fn ($query) => $query->whereKeyNot($personnel->id))
                ->update(['linked_user_id' => null, 'updated_by' => $actor->id, 'updated_at' => now()]);

            if ($personnel) {
                $personnel->forceFill([
                    'linked_user_id' => $user->id,
                    'updated_by' => $actor->id,
                ])->save();
            }

            $user->refresh()->load(['role', 'defaultBranch', 'personnel']);

            $this->audit->record(
                action: $before ? 'EDIT_USER' : 'CREATE_USER',
                module: 'users',
                entityType: User::class,
                entityId: $user->code ?? $user->id,
                before: $before,
                after: $this->snapshot($user),
                actor: $actor,
            );

            return $user;
        });
    }

    public function toggle(User $actor, User $user): User
    {
        if ($user->business_id !== $actor->business_id) {
            abort(404);
        }

        $next = strtoupper((string) $user->status) === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE';
        $role = $user->role()->firstOrFail();
        $this->guardLastSuperAdmin($user, $role, $next);

        $before = $this->snapshot($user->loadMissing(['role', 'defaultBranch', 'personnel']));

        $user->forceFill([
            'status' => $next,
            'failed_attempts' => 0,
            'locked_until' => null,
        ])->save();

        $user->refresh()->load(['role', 'defaultBranch', 'personnel']);

        $this->audit->record(
            action: $next === 'ACTIVE' ? 'ACTIVATE_USER' : 'DEACTIVATE_USER',
            module: 'users',
            entityType: User::class,
            entityId: $user->code ?? $user->id,
            before: $before,
            after: $this->snapshot($user),
            actor: $actor,
        );

        return $user;
    }

    public function resetAccessCode(User $actor, User $user, string $accessCode, bool $forceReset = true): User
    {
        if ($user->business_id !== $actor->business_id) {
            abort(404);
        }

        $user->forceFill([
            'password' => $accessCode,
            'force_reset' => $forceReset,
            'failed_attempts' => 0,
            'locked_until' => null,
        ])->save();

        $this->audit->record(
            action: 'RESET_USER_ACCESS_CODE',
            module: 'users',
            entityType: User::class,
            entityId: $user->code ?? $user->id,
            after: ['force_reset' => $forceReset],
            reason: 'Administrator reset the user access code.',
            actor: $actor,
        );

        return $user->fresh(['role', 'defaultBranch', 'personnel']);
    }

    public function serialize(User $user): array
    {
        return [
            'id' => $user->id,
            'code' => $user->code,
            'username' => $user->username,
            'display_name' => $user->display_name ?: $user->name,
            'email' => $user->email,
            'status' => $user->status,
            'force_reset' => (bool) $user->force_reset,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'role' => $user->role ? [
                'id' => $user->role->id,
                'code' => $user->role->code,
                'name' => $user->role->name,
                'is_super_admin' => (bool) $user->role->is_super_admin,
            ] : null,
            'default_branch' => $user->defaultBranch ? [
                'id' => $user->defaultBranch->id,
                'code' => $user->defaultBranch->code,
                'name' => $user->defaultBranch->name,
            ] : null,
            'personnel' => $user->personnel ? [
                'id' => $user->personnel->id,
                'code' => $user->personnel->code,
                'full_name' => $user->personnel->full_name,
                'status' => $user->personnel->status,
            ] : null,
        ];
    }

    private function guardLastSuperAdmin(User $user, Role $nextRole, string $nextStatus): void
    {
        $currentRole = $user->role()->first();
        $currentlyActiveSuperAdmin = $user->isActive() && (bool) $currentRole?->is_super_admin;
        $remainsActiveSuperAdmin = $nextStatus === 'ACTIVE' && (bool) $nextRole->is_super_admin;

        if (! $currentlyActiveSuperAdmin || $remainsActiveSuperAdmin) {
            return;
        }

        $otherActiveSuperAdmins = User::query()
            ->where('business_id', $user->business_id)
            ->where('status', 'ACTIVE')
            ->whereKeyNot($user->id)
            ->whereHas('role', fn ($query) => $query->where('is_super_admin', true)->where('active', true))
            ->exists();

        if (! $otherActiveSuperAdmins) {
            throw new DomainException('The last active Super Admin cannot be deactivated or assigned to a non-Super Admin role.');
        }
    }

    private function nextAvailableUserCode(int $businessId): string
    {
        do {
            $code = $this->codes->next('users', 'USR-', 4, $businessId);
        } while (User::query()->where('code', $code)->exists());

        return $code;
    }

    private function snapshot(User $user): array
    {
        return [
            'code' => $user->code,
            'username' => $user->username,
            'display_name' => $user->display_name ?: $user->name,
            'email' => $user->email,
            'status' => $user->status,
            'force_reset' => (bool) $user->force_reset,
            'role_code' => $user->role?->code,
            'default_branch_code' => $user->defaultBranch?->code,
            'personnel_code' => $user->personnel?->code,
        ];
    }
}
