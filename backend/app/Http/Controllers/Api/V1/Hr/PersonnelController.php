<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Hr\SavePersonnelRequest;
use App\Models\Branch;
use App\Models\Personnel;
use App\Models\ReferenceGroup;
use App\Models\User;
use App\Services\MasterData\PersonnelService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PersonnelController extends Controller
{
    public function index(Request $request, PersonnelService $service): JsonResponse
    {
        $businessId = $request->user()->business_id;

        $personnel = Personnel::query()
            ->where('business_id', $businessId)
            ->with(['position', 'branch', 'employmentType', 'personnelType', 'compensationRule', 'linkedUser'])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->map(fn (Personnel $record) => $service->serialize($record));

        return response()->json([
            'personnel' => $personnel,
            'references' => $this->referenceSets($businessId),
            'branches' => Branch::query()
                ->where('business_id', $businessId)
                ->where('status', 'ACTIVE')
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'users' => User::query()
                ->where('business_id', $businessId)
                ->where('status', 'ACTIVE')
                ->orderBy('display_name')
                ->get(['id', 'code', 'username', 'display_name']),
        ]);
    }

    public function store(SavePersonnelRequest $request, PersonnelService $service): JsonResponse
    {
        try {
            $record = $service->save($request->user(), $request->validated());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Personnel record created.',
            'personnel' => $service->serialize($record),
        ], 201);
    }

    public function update(
        SavePersonnelRequest $request,
        Personnel $personnel,
        PersonnelService $service,
    ): JsonResponse {
        try {
            $record = $service->save($request->user(), $request->validated(), $personnel);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Personnel record updated.',
            'personnel' => $service->serialize($record),
        ]);
    }

    private function referenceSets(int $businessId): array
    {
        $codes = ['EMPLOYEE_POSITION', 'EMPLOYMENT_TYPE', 'PERSONNEL_TYPE', 'COMPENSATION_RULE'];

        return ReferenceGroup::query()
            ->where('business_id', $businessId)
            ->whereIn('code', $codes)
            ->with(['values' => fn ($query) => $query->where('active', true)->orderBy('sort_order')->orderBy('display_value')])
            ->get()
            ->mapWithKeys(fn (ReferenceGroup $group) => [
                $group->code => $group->values->map(fn ($value) => [
                    'id' => $value->id,
                    'code' => $value->code,
                    'display_value' => $value->display_value,
                ])->values(),
            ])
            ->all();
    }
}
