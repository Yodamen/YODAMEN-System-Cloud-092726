<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\SaveRoleRequest;
use App\Http\Requests\Api\V1\Admin\UpdateRolePermissionsRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Security\RoleAdministrationService;
use App\Services\Security\RolePermissionService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RolePermissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;

        return response()->json([
            'roles' => Role::query()
                ->where('business_id', $businessId)
                ->with(['permissions:id,code,module,action,name'])
                ->withCount(['users as active_users_count' => fn ($query) => $query->where('status', 'ACTIVE')])
                ->orderByDesc('active')
                ->orderBy('name')
                ->get()
                ->map(fn (Role $role) => [
                    'id' => $role->id,
                    'code' => $role->code,
                    'name' => $role->name,
                    'description' => $role->description,
                    'active' => $role->active,
                    'is_system' => $role->is_system,
                    'is_super_admin' => $role->is_super_admin,
                    'active_users_count' => (int) $role->active_users_count,
                    'permissions' => $role->permissions->pluck('code')->sort()->values(),
                ]),
            'permission_catalog' => Permission::query()
                ->orderBy('module')
                ->orderBy('action')
                ->get(['id', 'code', 'module', 'action', 'name']),
        ]);
    }

    public function store(
        SaveRoleRequest $request,
        RoleAdministrationService $service,
    ): JsonResponse {
        try {
            $role = $service->save($request->user(), $request->validated());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Role created.',
            'role' => $this->serializeRole($role),
        ], 201);
    }

    public function save(
        SaveRoleRequest $request,
        Role $role,
        RoleAdministrationService $service,
    ): JsonResponse {
        try {
            $updated = $service->save($request->user(), $request->validated(), $role);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Role updated.',
            'role' => $this->serializeRole($updated),
        ]);
    }

    public function toggle(
        Request $request,
        Role $role,
        RoleAdministrationService $service,
    ): JsonResponse {
        try {
            $updated = $service->toggle($request->user(), $role);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => $updated->active ? 'Role activated.' : 'Role deactivated.',
            'role' => $this->serializeRole($updated),
        ]);
    }

    public function update(
        UpdateRolePermissionsRequest $request,
        Role $role,
        RolePermissionService $service,
    ): JsonResponse {
        if ($role->business_id !== $request->user()->business_id) {
            abort(404);
        }

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

    private function serializeRole(Role $role): array
    {
        return [
            'id' => $role->id,
            'code' => $role->code,
            'name' => $role->name,
            'description' => $role->description,
            'active' => (bool) $role->active,
            'is_system' => (bool) $role->is_system,
            'is_super_admin' => (bool) $role->is_super_admin,
            'permissions' => $role->permissions->pluck('code')->sort()->values(),
        ];
    }
}
