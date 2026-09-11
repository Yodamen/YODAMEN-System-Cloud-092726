<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdateRolePermissionsRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Security\RolePermissionService;
use DomainException;
use Illuminate\Http\JsonResponse;

class RolePermissionController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'roles' => Role::query()
                ->with(['permissions:id,code,module,action,name'])
                ->orderBy('name')
                ->get()
                ->map(fn (Role $role) => [
                    'id' => $role->id,
                    'code' => $role->code,
                    'name' => $role->name,
                    'description' => $role->description,
                    'active' => $role->active,
                    'is_super_admin' => $role->is_super_admin,
                    'permissions' => $role->permissions->pluck('code')->sort()->values(),
                ]),
            'permission_catalog' => Permission::query()
                ->orderBy('module')
                ->orderBy('action')
                ->get(['id', 'code', 'module', 'action', 'name']),
        ]);
    }

    public function update(
        UpdateRolePermissionsRequest $request,
        Role $role,
        RolePermissionService $service,
    ): JsonResponse {
        try {
            $updated = $service->update(
                $role,
                $request->validated()['permissions'],
                $request->user(),
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Role permissions updated.',
            'role' => [
                'code' => $updated->code,
                'name' => $updated->name,
                'permissions' => $updated->permissions->pluck('code')->sort()->values(),
            ],
        ]);
    }
}
