<?php

namespace App\Http\Controllers\Api\V1\Customers;

use App\Http\Controllers\Controller;
use App\Models\CustomerClassCriterion;
use App\Models\CustomerTypeCriterion;
use App\Services\Customers\CustomerClassificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CustomerCriteriaController extends Controller
{
    public function __construct(private readonly CustomerClassificationService $classification)
    {
    }

    public function types(Request $request): JsonResponse
    {
        $rows = CustomerTypeCriterion::query()
            ->where('business_id', $request->user()->business_id)
            ->orderBy('min_completed_visits')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'criteria' => $rows->map(fn (CustomerTypeCriterion $row) => $this->typeResource($row))->values(),
        ]);
    }

    public function updateTypes(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'criteria' => ['required', 'array', 'min:1', 'max:20'],
            'criteria.*.id' => ['nullable', 'integer'],
            'criteria.*.name' => ['required', 'string', 'max:80'],
            'criteria.*.color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'criteria.*.min_completed_visits' => ['required', 'integer', 'min:0', 'max:1000000'],
            'criteria.*.active' => ['nullable', 'boolean'],
        ]);
        $data = $validator->validate();

        $rows = collect($data['criteria'])
            ->map(fn (array $row) => [
                ...$row,
                'name' => trim((string) $row['name']),
                'color' => strtoupper(trim((string) $row['color'])),
                'min_completed_visits' => (int) $row['min_completed_visits'],
                'active' => array_key_exists('active', $row) ? (bool) $row['active'] : true,
            ])
            ->sortBy('min_completed_visits')
            ->values();

        if ($rows->pluck('name')->map(fn ($name) => mb_strtolower($name))->duplicates()->isNotEmpty()) {
            return response()->json(['message' => 'Customer Type names must be unique.'], 422);
        }

        $activeRows = $rows->where('active', true)->values();
        if ($activeRows->isEmpty()) {
            return response()->json(['message' => 'At least one active Customer Type is required.'], 422);
        }

        if ((int) $activeRows->min('min_completed_visits') !== 0) {
            return response()->json(['message' => 'One active Customer Type must start at 0 completed visits.'], 422);
        }

        if ($activeRows->pluck('min_completed_visits')->duplicates()->isNotEmpty()) {
            return response()->json(['message' => 'Each active Customer Type must start at a different completed-visit count.'], 422);
        }

        $user = $request->user();
        DB::transaction(function () use ($rows, $user) {
            $existing = CustomerTypeCriterion::query()
                ->where('business_id', $user->business_id)
                ->get()
                ->keyBy('id');
            $keptIds = [];

            foreach ($rows as $index => $row) {
                $model = null;
                if (! empty($row['id'])) {
                    $model = $existing->get((int) $row['id']);
                    if (! $model) abort(404);
                }

                if (! $model) {
                    $baseCode = substr(strtoupper(Str::slug($row['name'], '_')) ?: 'TYPE', 0, 24);
                    $candidate = $baseCode;
                    $counter = 2;
                    while (CustomerTypeCriterion::query()->where('business_id', $user->business_id)->where('code', $candidate)->exists()) {
                        $suffix = '_'.$counter++;
                        $candidate = substr($baseCode, 0, max(1, 30 - strlen($suffix))).$suffix;
                    }
                    $model = new CustomerTypeCriterion([
                        'business_id' => $user->business_id,
                        'code' => $candidate,
                        'created_by' => $user->id,
                    ]);
                }

                $model->fill([
                    'name' => $row['name'],
                    'color' => $row['color'],
                    'min_completed_visits' => $row['min_completed_visits'],
                    'sort_order' => ($index + 1) * 10,
                    'active' => $row['active'],
                    'updated_by' => $user->id,
                ]);
                $model->save();
                $keptIds[] = $model->id;
            }

            CustomerTypeCriterion::query()
                ->where('business_id', $user->business_id)
                ->when($keptIds !== [], fn ($query) => $query->whereNotIn('id', $keptIds))
                ->delete();
        });

        $this->classification->clear((int) $user->business_id);

        return $this->types($request);
    }

    public function classes(Request $request): JsonResponse
    {
        $rows = CustomerClassCriterion::query()
            ->where('business_id', $request->user()->business_id)
            ->orderBy('min_lifetime_value')
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'criteria' => $rows->map(fn (CustomerClassCriterion $row) => $this->classResource($row))->values(),
        ]);
    }

    public function updateClasses(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'criteria' => ['present', 'array', 'max:20'],
            'criteria.*.id' => ['nullable', 'integer'],
            'criteria.*.name' => ['required', 'string', 'max:100'],
            'criteria.*.min_lifetime_value' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'criteria.*.active' => ['nullable', 'boolean'],
        ]);
        $data = $validator->validate();
        $rows = collect($data['criteria'])
            ->map(fn ($row) => [
                ...$row,
                'name' => trim((string) $row['name']),
                'min_lifetime_value' => round((float) $row['min_lifetime_value'], 2),
                'active' => array_key_exists('active', $row) ? (bool) $row['active'] : true,
            ])
            ->sortBy('min_lifetime_value')
            ->values();

        if ($rows->pluck('name')->map(fn ($name) => mb_strtolower($name))->duplicates()->isNotEmpty()) {
            return response()->json(['message' => 'Customer Class names must be unique.'], 422);
        }

        if ($rows->pluck('min_lifetime_value')->duplicates()->isNotEmpty()) {
            return response()->json(['message' => 'Each Customer Class must start at a different Lifetime Value.'], 422);
        }

        $user = $request->user();
        DB::transaction(function () use ($rows, $user) {
            $existing = CustomerClassCriterion::query()
                ->where('business_id', $user->business_id)
                ->get()
                ->keyBy('id');
            $keptIds = [];

            foreach ($rows as $index => $row) {
                $model = null;
                if (! empty($row['id'])) {
                    $model = $existing->get((int) $row['id']);
                    if (! $model) abort(404);
                }

                if (! $model) {
                    $baseCode = strtoupper(Str::slug($row['name'], '_')) ?: 'CLASS';
                    $candidate = $baseCode;
                    $counter = 2;
                    while (CustomerClassCriterion::query()->where('business_id', $user->business_id)->where('code', $candidate)->exists()) {
                        $candidate = $baseCode.'_'.$counter++;
                    }
                    $model = new CustomerClassCriterion([
                        'business_id' => $user->business_id,
                        'code' => $candidate,
                        'created_by' => $user->id,
                    ]);
                }

                $model->fill([
                    'name' => $row['name'],
                    'min_lifetime_value' => $row['min_lifetime_value'],
                    'sort_order' => ($index + 1) * 10,
                    'active' => $row['active'],
                    'updated_by' => $user->id,
                ]);
                $model->save();
                $keptIds[] = $model->id;
            }

            CustomerClassCriterion::query()
                ->where('business_id', $user->business_id)
                ->when($keptIds !== [], fn ($query) => $query->whereNotIn('id', $keptIds))
                ->delete();
        });

        $this->classification->clear((int) $user->business_id);

        return $this->classes($request);
    }

    private function typeResource(CustomerTypeCriterion $row): array
    {
        return [
            'id' => $row->id,
            'code' => $row->code,
            'name' => $row->name,
            'color' => strtoupper((string) ($row->color ?: '#343434')),
            'min_completed_visits' => (int) $row->min_completed_visits,
            'active' => (bool) $row->active,
        ];
    }

    private function classResource(CustomerClassCriterion $row): array
    {
        return [
            'id' => $row->id,
            'code' => $row->code,
            'name' => $row->name,
            'min_lifetime_value' => (float) $row->min_lifetime_value,
            'active' => (bool) $row->active,
        ];
    }
}
