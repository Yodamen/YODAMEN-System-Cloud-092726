<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Operations\SaveCustomerRequest;
use App\Models\Customer;
use App\Models\ReferenceGroup;
use App\Models\Sale;
use App\Models\Visit;
use App\Services\Operations\CustomerService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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
        $metrics = $this->metricsForCustomers($rows, (int) $user->business_id);

        return response()->json([
            'customers' => $rows->map(fn (Customer $customer) => $this->resource($customer, $metrics[$customer->id] ?? [])),
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
        $metrics = $this->metricsForCustomers(collect([$customer]), (int) $request->user()->business_id);

        return response()->json(['customer' => $this->resource($customer, $metrics[$customer->id] ?? [])]);
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

    private function metricsForCustomers(Collection $customers, int $businessId): array
    {
        $customerIds = $customers->pluck('id')->map(fn ($id) => (int) $id)->filter()->values();
        if ($customerIds->isEmpty()) {
            return [];
        }

        $visits = Visit::query()
            ->where('business_id', $businessId)
            ->whereIn('customer_id', $customerIds)
            ->whereNotIn('status', ['CANCELLED', 'CANCELED', 'NO_SHOW'])
            ->get(['id', 'customer_id', 'visit_date', 'status']);

        $sales = Sale::query()
            ->where('business_id', $businessId)
            ->whereIn('customer_id', $customerIds)
            ->with(['payments' => fn ($query) => $query->where('status', 'RECEIVED')])
            ->get();

        $today = now(config('yodamen.business_timezone', 'Asia/Manila'))->startOfDay();
        $metrics = [];

        foreach ($customerIds as $customerId) {
            $customerVisits = $visits->where('customer_id', $customerId);
            $lastVisit = $customerVisits->sortByDesc(fn (Visit $visit) => $visit->visit_date?->format('Y-m-d') ?? '')->first()?->visit_date;
            $completedVisits = $customerVisits->filter(fn (Visit $visit) => strtoupper((string) $visit->status) === 'COMPLETED')->count();

            $lifetimeValue = 0.0;
            foreach ($sales->where('customer_id', $customerId) as $sale) {
                $received = (float) $sale->payments->sum(fn ($payment) => (float) $payment->amount);
                $lifetimeValue += min((float) $sale->gross_amount, $received);
            }

            $metrics[$customerId] = [
                'last_visit' => $lastVisit?->format('Y-m-d'),
                'days_since_last_visit' => $lastVisit ? (int) $lastVisit->startOfDay()->diffInDays($today) : null,
                'lifetime_value' => round($lifetimeValue, 2),
                'total_completed_visits' => $completedVisits,
            ];
        }

        return $metrics;
    }

    private function resource(Customer $customer, array $metrics = []): array
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
            'last_visit' => $metrics['last_visit'] ?? null,
            'days_since_last_visit' => $metrics['days_since_last_visit'] ?? null,
            'lifetime_value' => (float) ($metrics['lifetime_value'] ?? 0),
            'total_completed_visits' => (int) ($metrics['total_completed_visits'] ?? 0),
            'metrics_pending' => false,
            'created_at' => $customer->created_at?->toIso8601String(),
            'updated_at' => $customer->updated_at?->toIso8601String(),
        ];
    }
}
