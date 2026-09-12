<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Operations\CancelQueueRequest;
use App\Http\Requests\Api\V1\Operations\SaveWalkInRequest;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Personnel;
use App\Models\QueueEntry;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Services\Foundation\BusinessClock;
use App\Services\Operations\QueueService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QueueController extends Controller
{
    public function __construct(
        private readonly QueueService $queue,
        private readonly BusinessClock $clock,
    ) {
    }

    public function bootstrap(Request $request): JsonResponse
    {
        $user = $request->user();
        return response()->json([
            'business_date' => $this->clock->today(),
            'customers' => Customer::query()->where('business_id', $user->business_id)->where('status', 'ACTIVE')
                ->orderBy('last_name')->orderBy('first_name')->get(['id', 'code', 'first_name', 'last_name', 'mobile_number'])
                ->map(fn ($row) => ['id' => $row->id, 'code' => $row->code, 'name' => trim($row->first_name.' '.$row->last_name), 'mobile_number' => $row->mobile_number]),
            'services' => $this->serviceOptions($user->business_id, $user->default_branch_id),
            'personnel' => Personnel::query()->where('business_id', $user->business_id)->where('status', 'ACTIVE')->where('can_perform_services', true)
                ->orderBy('last_name')->orderBy('first_name')->get(['id', 'code', 'first_name', 'last_name'])
                ->map(fn ($row) => ['id' => $row->id, 'code' => $row->code, 'name' => trim($row->first_name.' '.$row->last_name)]),
            'customer_types' => [['code' => 'NEW', 'value' => 'New'], ['code' => 'RETURNEE', 'value' => 'Returnee']],
            'phase_notes' => [
                'advance_payment' => 'Advance payment is intentionally deferred until the immutable Payments ledger is active.',
                'service_credit' => 'Service Credit claims activate with Package Enrollment and Service Credit tables.',
                'servicing' => 'Starting a Visit from Queuing activates in the next Operations package.',
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $date = (string) $request->query('date', $this->clock->today());
        $status = strtoupper((string) $request->query('status', 'WAITING'));
        if (! in_array($status, ['WAITING', 'CANCELLED', 'ALL'], true)) $status = 'WAITING';

        $rows = $this->queue->entriesForDate($request->user(), $date, $status);
        return response()->json(['queue' => $rows->map(fn (QueueEntry $row) => $this->resource($row))->values()]);
    }

    public function show(Request $request, QueueEntry $queueEntry): JsonResponse
    {
        $this->assertOwned($request, $queueEntry);
        $queueEntry->load(['customer', 'booking', 'branch', 'serviceLines.service', 'serviceLines.assignedPersonnel', 'retailLines']);
        return response()->json(['queue_entry' => $this->resource($queueEntry)]);
    }

    public function checkIn(Request $request, Booking $booking): JsonResponse
    {
        try { $queue = $this->queue->checkInBooking($request->user(), $booking); }
        catch (DomainException $e) { return response()->json(['message' => $e->getMessage()], 422); }
        return response()->json(['queue_entry' => $this->resource($queue)]);
    }

    public function createWalkIn(SaveWalkInRequest $request): JsonResponse
    {
        try { $queue = $this->queue->createWalkIn($request->user(), $request->validated()); }
        catch (DomainException $e) { return response()->json(['message' => $e->getMessage()], 422); }
        return response()->json(['queue_entry' => $this->resource($queue)], 201);
    }

    public function cancel(CancelQueueRequest $request, QueueEntry $queueEntry): JsonResponse
    {
        try { $queue = $this->queue->cancel($request->user(), $queueEntry, $request->validated()['reason']); }
        catch (DomainException $e) { return response()->json(['message' => $e->getMessage()], 422); }
        return response()->json(['queue_entry' => $this->resource($queue)]);
    }

    private function serviceOptions(int $businessId, ?int $branchId): array
    {
        return Service::query()->with(['statusReference', 'category'])->where('business_id', $businessId)
            ->whereHas('statusReference', fn ($q) => $q->where('code', 'ACTIVE'))->orderBy('name')->get()
            ->map(function (Service $service) use ($branchId) {
                $today = $this->clock->today();
                $price = ServicePrice::query()->where('service_id', $service->id)->whereDate('effective_from', '<=', $today)
                    ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $today))
                    ->where(function ($q) use ($branchId) {
                        $branchId ? $q->where('branch_id', $branchId)->orWhereNull('branch_id') : $q->whereNull('branch_id');
                    })->orderByRaw('branch_id is null')->orderByDesc('effective_from')->first();
                return ['id' => $service->id, 'code' => $service->code, 'name' => $service->name,
                    'category' => $service->category?->display_value, 'current_price' => $price ? (float) $price->standard_price : null];
            })->values()->all();
    }

    private function resource(QueueEntry $row): array
    {
        return [
            'id' => $row->id, 'code' => $row->code, 'priority_no' => $row->getAttribute('priority_no'),
            'booking' => $row->booking ? ['id' => $row->booking->id, 'code' => $row->booking->code, 'status' => $row->booking->status] : null,
            'customer' => $row->customer ? ['id' => $row->customer->id, 'code' => $row->customer->code,
                'name' => trim($row->customer->first_name.' '.$row->customer->last_name), 'mobile_number' => $row->customer->mobile_number] : null,
            'branch' => $row->branch ? ['id' => $row->branch->id, 'code' => $row->branch->code, 'name' => $row->branch->name] : null,
            'customer_type' => $row->customer_type, 'queue_date' => $row->queue_date?->format('Y-m-d'),
            'check_in_at' => $row->check_in_at?->toIso8601String(), 'check_in_time' => $row->check_in_at?->format('H:i'),
            'source' => $row->source, 'status' => $row->status,
            'scheduled_date' => $row->scheduled_date?->format('Y-m-d'), 'scheduled_time' => $row->scheduled_time ? substr((string) $row->scheduled_time, 0, 5) : null,
            'notes' => $row->notes, 'cancellation_reason' => $row->cancellation_reason,
            'services' => $row->serviceLines->map(fn ($line) => [
                'id' => $line->id, 'code' => $line->code, 'service_id' => $line->service_id,
                'service_name' => $line->service_name_snapshot, 'source_type' => $line->source_type, 'quantity' => $line->quantity,
                'assigned_personnel_id' => $line->assigned_personnel_id,
                'assigned_personnel_name' => $line->assigned_personnel_name_snapshot, 'status' => $line->status, 'notes' => $line->notes,
            ])->values(),
            'retail_items' => $row->retailLines->map(fn ($line) => [
                'id' => $line->id, 'code' => $line->code, 'item_name' => $line->item_name_snapshot,
                'quantity' => $line->quantity, 'unit_price' => (float) $line->unit_price_snapshot, 'notes' => $line->notes,
            ])->values(),
            'can_cancel' => $row->isWaiting(),
        ];
    }

    private function assertOwned(Request $request, QueueEntry $queue): void
    {
        if ($queue->business_id !== $request->user()->business_id) abort(404);
    }
}
