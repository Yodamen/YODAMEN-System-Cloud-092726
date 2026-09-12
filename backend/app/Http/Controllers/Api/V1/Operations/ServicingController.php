<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Operations\AddVisitServiceRequest;
use App\Http\Requests\Api\V1\Operations\AssignVisitServiceStaffRequest;
use App\Http\Requests\Api\V1\Operations\CancelVisitServiceRequest;
use App\Models\Personnel;
use App\Models\QueueEntry;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\Visit;
use App\Models\VisitServiceLine;
use App\Services\Foundation\BusinessClock;
use App\Services\Operations\VisitService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServicingController extends Controller
{
    public function __construct(
        private readonly VisitService $visits,
        private readonly BusinessClock $clock,
    ) {
    }

    public function bootstrap(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'business_date' => $this->clock->today(),
            'services' => $this->serviceOptions($user->business_id, $user->default_branch_id),
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
            'phase_notes' => [
                'financial' => 'Service completion is operational only. It does not create payment, revenue, or commission.',
                'inventory' => 'Completion events are recorded now so Inventory Consumption can attach later without changing servicing history.',
                'service_credit' => 'Service Credit completion remains disabled until Package Enrollment and Service Credit ledgers are active.',
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $date = (string) $request->query('date', $this->clock->today());
        $status = strtoupper((string) $request->query('status', 'IN_SERVICE'));
        if (! in_array($status, ['IN_SERVICE', 'COMPLETED', 'CANCELLED', 'ALL'], true)) $status = 'IN_SERVICE';

        $rows = $this->visits->entriesForDate($request->user(), $date, $status);

        return response()->json(['visits' => $rows->map(fn (Visit $visit) => $this->resource($visit))->values()]);
    }

    public function show(Request $request, Visit $visit): JsonResponse
    {
        $this->assertOwned($request, $visit);
        $visit->load([
            'customer', 'booking', 'queueEntry', 'branch',
            'serviceLines.service', 'serviceLines.assignedPersonnel', 'serviceLines.completionEvent', 'retailLines',
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

    public function completeService(Request $request, VisitServiceLine $visitServiceLine): JsonResponse
    {
        try {
            $visit = $this->visits->completeService($request->user(), $visitServiceLine);
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

        return [
            'id' => $visit->id,
            'code' => $visit->code,
            'queue_entry' => $visit->queueEntry ? ['id' => $visit->queueEntry->id, 'code' => $visit->queueEntry->code, 'status' => $visit->queueEntry->status] : null,
            'booking' => $visit->booking ? ['id' => $visit->booking->id, 'code' => $visit->booking->code, 'status' => $visit->booking->status] : null,
            'customer' => $visit->customer ? [
                'id' => $visit->customer->id,
                'code' => $visit->customer->code,
                'name' => trim($visit->customer->first_name.' '.$visit->customer->last_name),
                'mobile_number' => $visit->customer->mobile_number,
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
                'notes' => $line->notes,
            ])->values(),
            'completed_service_count' => $services->where('status', 'COMPLETED')->count(),
            'cancelled_service_count' => $services->where('status', 'CANCELLED')->count(),
            'active_service_count' => $activeServices->count(),
            'all_active_services_completed' => $activeServices->isNotEmpty() && $activeServices->every(fn ($line) => $line['status'] === 'COMPLETED'),
            'can_add_service' => strtoupper((string) $visit->status) === 'IN_SERVICE',
        ];
    }

    private function serviceOptions(int $businessId, ?int $branchId): array
    {
        return Service::query()->with(['statusReference', 'category'])
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
                    'current_price' => $price ? (float) $price->standard_price : null,
                ];
            })->values()->all();
    }

    private function assertOwned(Request $request, Visit $visit): void
    {
        if ($visit->business_id !== $request->user()->business_id) abort(404);
    }
}
