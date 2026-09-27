<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Operations\AddVisitServiceRequest;
use App\Http\Requests\Api\V1\Operations\AssignVisitServiceStaffRequest;
use App\Http\Requests\Api\V1\Operations\CancelVisitServiceRequest;
use App\Http\Requests\Api\V1\Operations\CompleteVisitServiceRequest;
use App\Http\Requests\Api\V1\Operations\CompleteVisitServicesRequest;
use App\Http\Requests\Api\V1\Operations\SaveVisitRetailRequest;
use App\Models\Personnel;
use App\Models\QueueEntry;
use App\Models\RetailProduct;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\Visit;
use App\Models\VisitServiceLine;
use App\Services\Customers\CustomerClassificationService;
use App\Services\Foundation\BusinessClock;
use App\Services\Operations\VisitService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServicingController extends Controller
{
    public function __construct(
        private readonly VisitService $visits,
        private readonly \App\Services\Sales\PaymentLedgerService $paymentLedger,
        private readonly BusinessClock $clock,
        private readonly CustomerClassificationService $customerClassifications,
    ) {
    }

    public function bootstrap(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'business_date' => $this->clock->today(),
            'services' => $this->serviceOptions($user->business_id, $user->default_branch_id),
            'retail_products' => $this->retailProductOptions($user->business_id, $user->default_branch_id),
            'personnel' => Personnel::query()
                ->where('business_id', $user->business_id)
                ->where('status', 'ACTIVE')
                ->where('can_perform_services', true)
                ->orderBy('last_name')->orderBy('first_name')
                ->get(['id', 'code', 'first_name', 'last_name'])
                ->map(fn ($row) => [
                    'id' => $row->id,
                    'code' => $row->code,
                    'name' => trim($row->first_name.' '.$row->last_name),
                ])->values(),
            'retail_personnel' => Personnel::query()
                ->where('business_id', $user->business_id)
                ->where('status', 'ACTIVE')
                ->where('commission_eligible', true)
                ->orderBy('last_name')->orderBy('first_name')
                ->get(['id', 'code', 'first_name', 'last_name'])
                ->map(fn ($row) => [
                    'id' => $row->id,
                    'code' => $row->code,
                    'name' => trim($row->first_name.' '.$row->last_name),
                ])->values(),
            'phase_notes' => [
                'financial' => 'Service completion is operational only. It does not create payment, revenue, or commission.',
                'inventory' => 'Completion events are recorded now so Inventory Consumption can attach later without changing servicing history.',
                'service_credit' => 'Service Credit may be confirmed before completion and is consumed only when the service is completed.',
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $date = (string) $request->query('date', $this->clock->today());
        $status = strtoupper((string) $request->query('status', 'IN_SERVICE'));
        if (! in_array($status, ['IN_SERVICE', 'COMPLETED', 'CANCELLED', 'ALL'], true)) $status = 'IN_SERVICE';

        $rows = $this->visits->entriesForDate($request->user(), $date, $status);
        $this->customerClassifications->identitiesForCustomerIds(
            (int) $request->user()->business_id,
            $rows->pluck('customer_id')->all(),
        );

        return response()->json(['visits' => $rows->map(fn (Visit $visit) => $this->resource($visit))->values()]);
    }

    public function show(Request $request, Visit $visit): JsonResponse
    {
        $this->assertOwned($request, $visit);
        $visit->load([
            'customer', 'booking.payments', 'queueEntry', 'branch',
            'serviceLines.service', 'serviceLines.assignedPersonnel', 'serviceLines.completionEvent', 'retailLines.assignedPersonnel',
        ]);

        return response()->json(['visit' => $this->resource($visit)]);
    }

    public function startFromQueue(Request $request, QueueEntry $queueEntry): JsonResponse
    {
        try {
            $visit = $this->visits->startFromQueue($request->user(), $queueEntry);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['visit' => $this->resource($visit)]);
    }

    public function addService(AddVisitServiceRequest $request, Visit $visit): JsonResponse
    {
        try {
            $visit = $this->visits->addService($request->user(), $visit, $request->validated());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['visit' => $this->resource($visit)]);
    }

    public function updateRetailItems(SaveVisitRetailRequest $request, Visit $visit): JsonResponse
    {
        try {
            $visit = $this->visits->updateRetailItems(
                $request->user(),
                $visit,
                $request->validated()['retail_items'] ?? [],
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['visit' => $this->resource($visit)]);
    }

    public function startService(Request $request, VisitServiceLine $visitServiceLine): JsonResponse
    {
        try {
            $visit = $this->visits->startService($request->user(), $visitServiceLine);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['visit' => $this->resource($visit)]);
    }

    public function assignStaff(AssignVisitServiceStaffRequest $request, VisitServiceLine $visitServiceLine): JsonResponse
    {
        try {
            $visit = $this->visits->assignStaff(
                $request->user(),
                $visitServiceLine,
                $request->validated()['assigned_personnel_id'] ?? null,
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['visit' => $this->resource($visit)]);
    }

    public function completeService(CompleteVisitServiceRequest $request, VisitServiceLine $visitServiceLine): JsonResponse
    {
        try {
            $visit = $this->visits->completeService(
                $request->user(),
                $visitServiceLine,
                $request->validated(),
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['visit' => $this->resource($visit)]);
    }

    public function completeServices(CompleteVisitServicesRequest $request, Visit $visit): JsonResponse
    {
        try {
            $data = $request->validated();
            $visit = $this->visits->completeSelectedServices(
                $request->user(),
                $visit,
                $data['service_line_ids'],
                $data['retail_items'] ?? null,
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['visit' => $this->resource($visit)]);
    }

    public function cancelService(CancelVisitServiceRequest $request, VisitServiceLine $visitServiceLine): JsonResponse
    {
        try {
            $visit = $this->visits->cancelService(
                $request->user(),
                $visitServiceLine,
                $request->validated()['reason'],
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['visit' => $this->resource($visit)]);
    }

    public function completeVisit(Request $request, Visit $visit): JsonResponse
    {
        try {
            $visit = $this->visits->completeVisit($request->user(), $visit);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['visit' => $this->resource($visit)]);
    }

    private function resource(Visit $visit): array
    {
        $services = $visit->serviceLines->map(fn (VisitServiceLine $line) => [
            'id' => $line->id,
            'code' => $line->code,
            'service_id' => $line->service_id,
            'service_name' => $line->service_name_snapshot,
            'source_type' => $line->source_type,
            'source_reference_code' => $line->source_reference_code,
            'package_enrollment_id' => $line->package_enrollment_id,
            'service_credit_id' => $line->service_credit_id,
            'quantity' => $line->quantity,
            'assigned_personnel_id' => $line->assigned_personnel_id,
            'assigned_personnel_name' => $line->assigned_personnel_name_snapshot,
            'status' => $line->status,
            'started_at' => $line->started_at?->toIso8601String(),
            'started_time' => $line->started_at?->format('H:i'),
            'completed_at' => $line->completed_at?->toIso8601String(),
            'completed_time' => $line->completed_at?->format('H:i'),
            'cancelled_at' => $line->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $line->cancellation_reason,
            'notes' => $line->notes,
            'completion_event' => $line->completionEvent ? [
                'id' => $line->completionEvent->id,
                'code' => $line->completionEvent->code,
                'status' => $line->completionEvent->status,
            ] : null,
            'can_edit' => strtoupper((string) $visit->status) === 'IN_SERVICE' && ! $line->isFinalized(),
            'can_complete' => strtoupper((string) $visit->status) === 'IN_SERVICE' && ! $line->isFinalized(),
            'can_cancel' => strtoupper((string) $visit->status) === 'IN_SERVICE' && strtoupper((string) $line->status) !== 'COMPLETED',
        ])->values();

        $activeServices = $services->filter(fn ($line) => $line['status'] !== 'CANCELLED');
        $paymentTotals = $visit->booking
            ? $this->paymentLedger->bookingAllocationTotals($visit->booking)
            : ['advance' => 0.0, 'interim' => 0.0];
        $customerIdentity = $visit->customer
            ? $this->customerClassifications->identityForCustomer($visit->customer)
            : null;

        return [
            'id' => $visit->id,
            'code' => $visit->code,
            'queue_entry' => $visit->queueEntry ? ['id' => $visit->queueEntry->id, 'code' => $visit->queueEntry->code, 'status' => $visit->queueEntry->status] : null,
            'booking' => $visit->booking ? ['id' => $visit->booking->id, 'code' => $visit->booking->code, 'status' => $visit->booking->status] : null,
            'advance_payment_total' => $paymentTotals['advance'],
            'interim_payment_total' => $paymentTotals['interim'],
            'customer' => $visit->customer ? [
                'id' => $visit->customer->id,
                'code' => $visit->customer->code,
                'name' => trim($visit->customer->first_name.' '.$visit->customer->last_name),
                'mobile_number' => $visit->customer->mobile_number,
                'segment' => $customerIdentity['customer_type'] ?? $visit->customer->segment_label,
                'customer_type' => $customerIdentity['customer_type'] ?? $visit->customer->segment_label,
                'customer_type_color' => $customerIdentity['customer_type_color'] ?? '#343434',
                'customer_class' => $customerIdentity['customer_class'] ?? 'Unclassified',
            ] : null,
            'branch' => $visit->branch ? ['id' => $visit->branch->id, 'code' => $visit->branch->code, 'name' => $visit->branch->name] : null,
            'customer_type' => $visit->customer_type,
            'visit_date' => $visit->visit_date?->format('Y-m-d'),
            'check_in_at' => $visit->check_in_at?->toIso8601String(),
            'check_in_time' => $visit->check_in_at?->format('H:i'),
            'service_start_at' => $visit->service_start_at?->toIso8601String(),
            'service_start_time' => $visit->service_start_at?->format('H:i'),
            'check_out_at' => $visit->check_out_at?->toIso8601String(),
            'check_out_time' => $visit->check_out_at?->format('H:i'),
            'status' => $visit->status,
            'notes' => $visit->notes,
            'services' => $services,
            'retail_items' => $visit->retailLines->map(fn ($line) => [
                'id' => $line->id,
                'code' => $line->code,
                'item_name' => $line->item_name_snapshot,
                'quantity' => $line->quantity,
                'unit_price' => (float) $line->unit_price_snapshot,
                'assigned_personnel_id' => $line->assigned_personnel_id,
                'assigned_personnel_name' => $line->assigned_personnel_name_snapshot ?: ($line->assignedPersonnel ? trim($line->assignedPersonnel->first_name.' '.$line->assignedPersonnel->last_name) : null),
                'notes' => $line->notes,
            ])->values(),
            'completed_service_count' => $services->where('status', 'COMPLETED')->count(),
            'cancelled_service_count' => $services->where('status', 'CANCELLED')->count(),
            'active_service_count' => $activeServices->count(),
            'all_active_services_completed' => $activeServices->isNotEmpty() && $activeServices->every(fn ($line) => $line['status'] === 'COMPLETED'),
            'can_add_service' => strtoupper((string) $visit->status) === 'IN_SERVICE',
        ];
    }

    private function retailProductOptions(int $businessId, ?int $branchId): array
    {
        return RetailProduct::query()
            ->where('business_id', $businessId)
            ->where('status', 'ACTIVE')
            ->where('stock_type', 'INVENTORY')
            ->where('retail_available', true)
            ->with(['stocks' => fn ($query) => $branchId ? $query->where('branch_id', $branchId) : $query])
            ->orderBy('name')
            ->get()
            ->map(function (RetailProduct $product) {
                $stock = $product->stocks->first();
                $availableBasic = max(0, round((float) ($stock?->on_hand ?? 0) - (float) ($stock?->reserved ?? 0), 4));
                $sellingFactor = max(0.000001, (float) ($product->selling_to_basic_factor ?: 1));
                return [
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'selling_price' => $product->sellingPriceForDate(),
                    'available' => max(0, (int) floor(($availableBasic + 0.00005) / $sellingFactor)),
                ];
            })->values()->all();
    }

    private function serviceOptions(int $businessId, ?int $branchId): array
    {
        return Service::query()->with(['statusReference', 'category', 'serviceCount'])
            ->where('business_id', $businessId)
            ->whereHas('statusReference', fn ($q) => $q->where('code', 'ACTIVE'))
            ->orderBy('name')->get()
            ->map(function (Service $service) use ($branchId) {
                $today = $this->clock->today();
                $price = ServicePrice::query()
                    ->where('service_id', $service->id)
                    ->whereDate('effective_from', '<=', $today)
                    ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $today))
                    ->where(function ($q) use ($branchId) {
                        $branchId ? $q->where('branch_id', $branchId)->orWhereNull('branch_id') : $q->whereNull('branch_id');
                    })
                    ->orderByRaw('branch_id is null')
                    ->orderByDesc('effective_from')
                    ->first();

                return [
                    'id' => $service->id,
                    'code' => $service->code,
                    'name' => $service->name,
                    'category' => $service->category?->display_value,
                    'service_count' => $service->serviceCount?->code,
                    'number_of_sessions' => $service->number_of_sessions,
                    'current_price' => $price ? (float) $price->standard_price : null,
                ];
            })->values()->all();
    }

    private function assertOwned(Request $request, Visit $visit): void
    {
        if ($visit->business_id !== $request->user()->business_id) abort(404);
    }
}
