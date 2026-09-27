<?php

namespace App\Services\Security;

use App\Models\Role;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class RoleAdministrationService
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    public function save(User $actor, array $data, ?Role $role = null): Role
    {
        if ($role && $role->business_id !== $actor->business_id) {
            abort(404);
        }

        return DB::transaction(function () use ($actor, $data, $role) {
            $before = $role ? $this->snapshot($role) : null;

            if (! $role) {
                $role = Role::query()->create([
                    'business_id' => $actor->business_id,
                    'code' => strtoupper(trim($data['code'])),
                    'name' => trim($data['name']),
                    'description' => trim((string) ($data['description'] ?? '')) ?: null,
                    'is_super_admin' => false,
                    'is_system' => false,
                    'active' => true,
                ]);
            } else {
                $role->forceFill([
                    'name' => trim($data['name']),
                    'description' => trim((string) ($data['description'] ?? '')) ?: null,
                ])->save();
            }

            $this->audit->record(
                action: $before ? 'EDIT_ROLE' : 'CREATE_ROLE',
                module: 'users',
                entityType: Role::class,
                entityId: $role->code,
                before: $before,
                after: $this->snapshot($role),
                actor: $actor,
            );

            return $role->fresh('permissions');
        });
    }

    public function toggle(User $actor, Role $role): Role
    {
        if ($role->business_id !== $actor->business_id) {
            abort(404);
        }

        if ($role->is_super_admin || $role->code === 'ROLE-SA') {
            throw new DomainException('Super Admin role cannot be deactivated.');
        }

        $next = ! $role->active;

        if (! $next && $role->users()->where('status', 'ACTIVE')->exists()) {
            throw new DomainException('A role with active users cannot be deactivated. Reassign or deactivate those users first.');
        }

        $before = $this->snapshot($role);
        $role->forceFill(['active' => $next])->save();

        $this->audit->record(
            action: $next ? 'ACTIVATE_ROLE' : 'DEACTIVATE_ROLE',
            module: 'users',
            entityType: Role::class,
            entityId: $role->code,
            before: $before,
            after: $this->snapshot($role),
            actor: $actor,
        );

        return $role->fresh('permissions');
    }

    private function snapshot(Role $role): array
    {
        return [
            'code' => $role->code,
            'name' => $role->name,
            'description' => $role->description,
            'active' => (bool) $role->active,
            'is_system' => (bool) $role->is_system,
            'is_super_admin' => (bool) $role->is_super_admin,
        ];
    }
}
