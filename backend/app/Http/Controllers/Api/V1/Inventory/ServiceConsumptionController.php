<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Models\ServiceConsumptionRecipe;
use App\Services\Inventory\ServiceConsumptionRecipeService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceConsumptionController extends Controller
{
    public function index(Request $request, ServiceConsumptionRecipeService $service): JsonResponse
    {
        return response()->json($service->index($request->user()));
    }

    public function store(Request $request, ServiceConsumptionRecipeService $service): JsonResponse
    {
        $data = $this->validated($request);
        try {
            $recipe = $service->save($request->user(), $data);
            return response()->json(['message' => 'Service material recipe created.', 'recipe' => $service->serialize($recipe)], 201);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function update(Request $request, ServiceConsumptionRecipe $serviceConsumptionRecipe, ServiceConsumptionRecipeService $service): JsonResponse
    {
        $data = $this->validated($request);
        try {
            $recipe = $service->save($request->user(), $data, $serviceConsumptionRecipe);
            return response()->json(['message' => 'Service material recipe updated.', 'recipe' => $service->serialize($recipe)]);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'status' => ['required', 'in:ACTIVE,INACTIVE'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.retail_product_id' => ['required', 'integer', 'distinct', 'exists:retail_products,id'],
            'lines.*.quantity_per_service' => ['required', 'numeric', 'gt:0', 'max:100000000'],
        ]);
    }
}
