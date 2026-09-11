<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\SaveReferenceValueRequest;
use App\Models\ReferenceGroup;
use App\Models\ReferenceValue;
use App\Services\MasterData\ReferenceDataService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferenceDataController extends Controller
{
    public function categories(Request $request): JsonResponse
    {
        return response()->json([
            'categories' => ReferenceGroup::query()
                ->where('business_id', $request->user()->business_id)
                ->where('active', true)
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'description', 'is_system']),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $groupCode = strtoupper(trim((string) $request->query('group_code', '')));

        $query = ReferenceValue::query()
            ->whereHas('group', fn ($group) => $group->where('business_id', $request->user()->business_id))
            ->with('group:id,code,name');

        if ($groupCode !== '') {
            $query->whereHas('group', fn ($group) => $group->where('code', $groupCode));
        }

        return response()->json([
            'references' => $query
                ->orderBy('reference_group_id')
                ->orderBy('sort_order')
                ->orderBy('display_value')
                ->get()
                ->map(fn (ReferenceValue $value) => $this->payload($value)),
        ]);
    }

    public function store(SaveReferenceValueRequest $request, ReferenceDataService $service): JsonResponse
    {
        try {
            $value = $service->create($request->user(), $request->validated());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Reference option created.',
            'reference' => $this->payload($value),
        ], 201);
    }

    public function update(
        SaveReferenceValueRequest $request,
        ReferenceValue $referenceValue,
        ReferenceDataService $service,
    ): JsonResponse {
        try {
            $value = $service->update($request->user(), $referenceValue, $request->validated());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Reference option updated.',
            'reference' => $this->payload($value),
        ]);
    }

    public function toggle(Request $request, ReferenceValue $referenceValue, ReferenceDataService $service): JsonResponse
    {
        $value = $service->toggle($request->user(), $referenceValue);

        return response()->json([
            'message' => $value->active ? 'Reference option activated.' : 'Reference option deactivated.',
            'reference' => $this->payload($value),
        ]);
    }

    private function payload(ReferenceValue $value): array
    {
        return [
            'id' => $value->id,
            'group' => [
                'code' => $value->group?->code,
                'name' => $value->group?->name,
            ],
            'code' => $value->code,
            'display_value' => $value->display_value,
            'sort_order' => $value->sort_order,
            'is_system' => $value->is_system,
            'active' => $value->active,
        ];
    }
}
