<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ResetUserAccessCodeRequest;
use App\Http\Requests\Api\V1\Admin\SaveUserRequest;
use App\Models\Branch;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use App\Services\Security\UserAdministrationService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserAdministrationController extends Controller
{
    public function index(Request $request, UserAdministrationService $service): JsonResponse
    {
        $businessId = $request->user()->business_id;

        $users = User::query()
            ->where('business_id', $businessId)
            ->with(['role', 'defaultBranch', 'personnel'])
            ->orderBy('display_name')
            ->orderBy('username')
            ->get()
            ->map(fn (User $user) => $service->serialize($user));

        return response()->json([
            'users' => $users,
            'roles' => Role::query()
                ->where('business_id', $businessId)
                ->orderByDesc('active')
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'active', 'is_super_admin', 'is_system']),
            'branches' => Branch::query()
                ->where('business_id', $businessId)
                ->where('status', 'ACTIVE')
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'personnel' => Personnel::query()
                ->where('business_id', $businessId)
                ->with('linkedUser:id,code,display_name,username')
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get()
                ->map(fn (Personnel $personnel) => [
                    'id' => $personnel->id,
                    'code' => $personnel->code,
                    'full_name' => $personnel->full_name,
                    'status' => $personnel->status,
                    'linked_user' => $personnel->linkedUser ? [
                        'id' => $personnel->linkedUser->id,
                        'display_name' => $personnel->linkedUser->display_name,
                        'username' => $personnel->linkedUser->username,
                    ] : null,
                ]),
        ]);
    }

    public function store(SaveUserRequest $request, UserAdministrationService $service): JsonResponse
    {
        try {
            $user = $service->save($request->user(), $request->validated());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'User account created.',
            'user' => $service->serialize($user),
        ], 201);
    }

    public function update(
        SaveUserRequest $request,
        User $user,
        UserAdministrationService $service,
    ): JsonResponse {
        try {
            $updated = $service->save($request->user(), $request->validated(), $user);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'User account updated.',
            'user' => $service->serialize($updated),
        ]);
    }

    public function toggle(Request $request, User $user, UserAdministrationService $service): JsonResponse
    {
        try {
            $updated = $service->toggle($request->user(), $user);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => $updated->isActive() ? 'User account activated.' : 'User account deactivated.',
            'user' => $service->serialize($updated),
        ]);
    }

    public function resetAccessCode(
        ResetUserAccessCodeRequest $request,
        User $user,
        UserAdministrationService $service,
    ): JsonResponse {
        $updated = $service->resetAccessCode(
            $request->user(),
            $user,
            $request->string('access_code')->toString(),
            (bool) $request->input('force_reset', true),
        );

        return response()->json([
            'message' => 'Access Code reset successfully.',
            'user' => $service->serialize($updated),
        ]);
    }
}
