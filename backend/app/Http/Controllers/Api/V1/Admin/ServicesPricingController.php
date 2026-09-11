<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ChangeServicePriceRequest;
use App\Http\Requests\Api\V1\Admin\SaveServiceRequest;
use App\Models\ReferenceGroup;
use App\Models\Service;
use App\Services\MasterData\ServiceCatalogService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServicesPricingController extends Controller
{
    public function index(Request $request, ServiceCatalogService $service): JsonResponse
    {
        return response()->json([
            'services' => $service->list($request->user()->business_id),
            'references' => $this->referenceSets($request->user()->business_id),
        ]);
    }

    public function show(Request $request, Service $serviceRecord, ServiceCatalogService $service): JsonResponse
    {
        return response()->json([
            'service' => $service->details($request->user(), $serviceRecord),
            'references' => $this->referenceSets($request->user()->business_id),
        ]);
    }

    public function store(SaveServiceRequest $request, ServiceCatalogService $service): JsonResponse
    {
        try {
            $record = $service->create($request->user(), $request->validated());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Service created.',
            'service' => $service->details($request->user(), $record),
        ], 201);
    }

    public function update(
        SaveServiceRequest $request,
        Service $serviceRecord,
        ServiceCatalogService $service,
    ): JsonResponse {
        try {
            $record = $service->update($request->user(), $serviceRecord, $request->validated());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Service updated.',
            'service' => $service->details($request->user(), $record),
        ]);
    }

    public function changePrice(
        ChangeServicePriceRequest $request,
        Service $serviceRecord,
        ServiceCatalogService $service,
    ): JsonResponse {
        try {
            $price = $service->changePrice($request->user(), $serviceRecord, $request->validated());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Service price scheduled.',
            'price' => [
                'id' => $price->id,
                'code' => $price->code,
                'amount' => (float) $price->standard_price,
                'effective_from' => $price->effective_from?->toDateString(),
                'effective_until' => $price->effective_until?->toDateString(),
                'status' => $price->status,
            ],
        ], 201);
    }

    private function referenceSets(int $businessId): array
    {
        return ReferenceGroup::query()
            ->where('business_id', $businessId)
            ->whereIn('code', ['SERVICE_CATEGORY', 'SERVICE_COUNT', 'SERVICE_STATUS'])
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
