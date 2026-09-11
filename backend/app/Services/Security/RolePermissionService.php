<?php

namespace App\Services\Security;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class RolePermissionService
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    public function update(Role $role, array $permissionCodes, User $actor): Role
    {
        if ($role->is_super_admin || $role->code === 'ROLE-SA') {
            throw new DomainException('Super Admin permissions cannot be restricted.');
        }

        return DB::transaction(function () use ($role, $permissionCodes, $actor) {
            $lockedRole = Role::query()->lockForUpdate()->findOrFail($role->id);

            $before = $lockedRole->permissions()->orderBy('code')->pluck('code')->all();

            $requested = Permission::query()
                ->whereIn('code', array_values(array_unique($permissionCodes)))
                ->get();

            if ($requested->count() !== count(array_unique($permissionCodes))) {
                throw new DomainException('One or more permissions are invalid.');
            }

            $byModule = $requested->groupBy('module');
            $viewPermissions = Permission::query()
                ->whereIn('module', $byModule->keys())
                ->where('action', 'VIEW')
                ->get()
                ->keyBy('module');

            $resolvedIds = $requested->pluck('id')->all();

            foreach ($byModule as $module => $permissions) {
                $hasNonView = $permissions->contains(fn (Permission $permission) => $permission->action !== 'VIEW');
                if ($hasNonView && isset($viewPermissions[$module])) {
                    $resolvedIds[] = $viewPermissions[$module]->id;
                }
            }

            $lockedRole->permissions()->sync(array_values(array_unique($resolvedIds)));

            $after = $lockedRole->permissions()->orderBy('code')->pluck('code')->all();

            $this->audit->record(
                action: 'UPDATE_ROLE_ACTION_PERMISSIONS',
                module: 'users',
                entityType: Role::class,
                entityId: $lockedRole->code,
                before: ['permissions' => $before],
                after: ['permissions' => $after],
                actor: $actor,
            );

            return $lockedRole->load('permissions');
        });
    }
}
