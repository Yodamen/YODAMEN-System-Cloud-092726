<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Operations\SaveCustomerRequest;
use App\Models\Customer;
use App\Models\ReferenceGroup;
use App\Services\Operations\CustomerService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function __construct(private readonly CustomerService $customers)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Customer::query()
            ->with(['gender:id,code,display_value', 'leadSource:id,code,display_value'])
            ->where('business_id', $user->business_id);

        if ($search = trim((string) $request->query('search', ''))) {
            $normalized = $this->customers->normalizeMobile($search);
            $query->where(function ($builder) use ($search, $normalized) {
                $builder->where('code', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhereRaw("concat(first_name, ' ', last_name) like ?", ["%{$search}%"])
                    ->orWhere('mobile_number', 'like', "%{$search}%");
                if ($normalized !== '') {
                    $builder->orWhere('mobile_normalized', 'like', "%{$normalized}%");
                }
            });
        }

        $status = strtoupper(trim((string) $request->query('status', '')));
        if (in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
            $query->where('status', $status);
        }

        $rows = $query->orderByDesc('registration_date')->orderByDesc('id')->get();

        return response()->json([
            'customers' => $rows->map(fn (Customer $customer) => $this->resource($customer)),
            'references' => [
                'genders' => $this->referenceOptions($user->business_id, 'GENDER'),
                'lead_sources' => $this->referenceOptions($user->business_id, 'LEAD_SOURCE'),
            ],
        ]);
    }

    public function show(Request $request, Customer $customer): JsonResponse
    {
        $this->assertOwned($request, $customer);
        $customer->load(['gender:id,code,display_value', 'leadSource:id,code,display_value']);

        return response()->json(['customer' => $this->resource($customer)]);
    }

    public function store(SaveCustomerRequest $request): JsonResponse
    {
        try {
            $customer = $this->customers->save($request->user(), $request->validated());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        return response()->json(['customer' => $this->resource($customer)], 201);
    }

    public function update(SaveCustomerRequest $request, Customer $customer): JsonResponse
    {
        try {
            $customer = $this->customers->save($request->user(), $request->validated(), $customer);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        return response()->json(['customer' => $this->resource($customer)]);
    }

    public function toggle(Request $request, Customer $customer): JsonResponse
    {
        $customer = $this->customers->toggle($request->user(), $customer);
        return response()->json(['customer' => $this->resource($customer)]);
    }

    private function assertOwned(Request $request, Customer $customer): void
    {
        if ($customer->business_id !== $request->user()->business_id) {
            abort(404);
        }
    }

    private function referenceOptions(int $businessId, string $groupCode): array
    {
        $group = ReferenceGroup::query()
            ->where('business_id', $businessId)
            ->where('code', $groupCode)
            ->with(['values' => fn ($query) => $query->where('active', true)->orderBy('sort_order')->orderBy('display_value')])
            ->first();

        return $group?->values->map(fn ($value) => [
            'code' => $value->code,
            'value' => $value->display_value,
        ])->values()->all() ?? [];
    }

    private function resource(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'code' => $customer->code,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'full_name' => $customer->full_name,
            'mobile_number' => $customer->mobile_number,
            'email' => $customer->email,
            'birthday' => $customer->birthday?->format('Y-m-d'),
            'birthday_month' => $customer->birthday?->month,
            'gender_code' => $customer->gender?->code,
            'gender' => $customer->gender?->display_value,
            'address' => $customer->address,
            'lead_source_code' => $customer->leadSource?->code,
            'lead_source' => $customer->leadSource?->display_value,
            'registration_date' => $customer->registration_date?->format('Y-m-d'),
            'status' => $customer->status,
            'notes' => $customer->notes,
            'last_visit' => null,
            'lifetime_value' => 0,
            'total_completed_visits' => 0,
            'metrics_pending' => true,
            'created_at' => $customer->created_at?->toIso8601String(),
            'updated_at' => $customer->updated_at?->toIso8601String(),
        ];
    }
}
