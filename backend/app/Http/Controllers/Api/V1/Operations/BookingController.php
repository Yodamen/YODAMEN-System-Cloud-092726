<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Operations\CancelBookingRequest;
use App\Http\Requests\Api\V1\Operations\RebookBookingRequest;
use App\Http\Requests\Api\V1\Operations\SaveBookingRequest;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Personnel;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Services\Foundation\BusinessClock;
use App\Services\Operations\BookingService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly BusinessClock $clock,
    ) {
    }

    public function bootstrap(Request $request): JsonResponse
    {
        $user = $request->user();
        return response()->json([
            'customers' => Customer::query()
                ->where('business_id', $user->business_id)
                ->where('status', 'ACTIVE')
                ->orderBy('last_name')->orderBy('first_name')
                ->get(['id', 'code', 'first_name', 'last_name', 'mobile_number'])
                ->map(fn ($customer) => [
                    'id' => $customer->id,
                    'code' => $customer->code,
                    'name' => trim($customer->first_name.' '.$customer->last_name),
                    'mobile_number' => $customer->mobile_number,
                ]),
            'services' => $this->serviceOptions($user->business_id, $user->default_branch_id),
            'personnel' => Personnel::query()
                ->where('business_id', $user->business_id)
                ->where('status', 'ACTIVE')
                ->where('can_perform_services', true)
                ->orderBy('last_name')->orderBy('first_name')
                ->get(['id', 'code', 'first_name', 'last_name'])
                ->map(fn ($personnel) => [
                    'id' => $personnel->id,
                    'code' => $personnel->code,
                    'name' => trim($personnel->first_name.' '.$personnel->last_name),
                ]),
            'customer_types' => [
                ['code' => 'NEW', 'value' => 'New'],
                ['code' => 'RETURNEE', 'value' => 'Returnee'],
            ],
            'booking_sources' => [
                ['code' => 'RESERVATION', 'value' => 'Reservation', 'enabled' => true],
                ['code' => 'WALK_IN', 'value' => 'Walk-in', 'enabled' => true, 'note' => 'Created from the Queuing page'],
            ],
            'phase_notes' => [
                'advance_payment' => 'Advance cash receipt will be activated with the Payments ledger. It is intentionally not stored on the Booking record.',
                'service_credit' => 'Service Credit claims will be activated with Package/Service Credit tables.',
                'walk_in' => 'Walk-Ins are created from Queuing and atomically create both Booking and Queue records.',
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Booking::query()
            ->with(['customer', 'branch', 'serviceLines.service', 'serviceLines.assignedPersonnel', 'retailLines', 'rebookings', 'queueEntry'])
            ->where('business_id', $user->business_id);

        $view = strtoupper(trim((string) $request->query('view', 'ACTIVE')));
        if ($view === 'ACTIVE') {
            $query->whereNotIn('status', ['COMPLETED', 'CANCELLED']);
        } elseif ($view === 'CANCELLED') {
            $query->where('status', 'CANCELLED');
        } elseif ($view === 'COMPLETED') {
            $query->where('status', 'COMPLETED');
        }

        if ($start = $request->query('start_date')) {
            $query->whereDate('booking_date', '>=', $start);
        }
        if ($end = $request->query('end_date')) {
            $query->whereDate('booking_date', '<=', $end);
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($builder) use ($search) {
                $builder->where('code', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($customerQuery) use ($search) {
                        $customerQuery->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('mobile_number', 'like', "%{$search}%");
                    });
            });
        }

        $businessDate = $this->clock->today();
        $rows = $query
            ->orderByRaw('case when booking_date >= ? then 0 else 1 end', [$businessDate])
            ->orderBy('booking_date')
            ->orderBy('booking_time')
            ->get();

        return response()->json(['bookings' => $rows->map(fn (Booking $booking) => $this->resource($booking))]);
    }

    public function show(Request $request, Booking $booking): JsonResponse
    {
        $this->assertOwned($request, $booking);
        $booking->load(['customer', 'branch', 'serviceLines.service', 'serviceLines.assignedPersonnel', 'retailLines', 'rebookings.actor', 'queueEntry']);
        return response()->json(['booking' => $this->resource($booking)]);
    }

    public function store(SaveBookingRequest $request): JsonResponse
    {
        try {
            $booking = $this->bookings->save($request->user(), $request->validated());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        return response()->json(['booking' => $this->resource($booking)], 201);
    }

    public function update(SaveBookingRequest $request, Booking $booking): JsonResponse
    {
        try {
            $booking = $this->bookings->save($request->user(), $request->validated(), $booking);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        return response()->json(['booking' => $this->resource($booking)]);
    }

    public function rebook(RebookBookingRequest $request, Booking $booking): JsonResponse
    {
        try {
            $booking = $this->bookings->rebook($request->user(), $booking, $request->validated());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        return response()->json(['booking' => $this->resource($booking)]);
    }

    public function cancel(CancelBookingRequest $request, Booking $booking): JsonResponse
    {
        try {
            $booking = $this->bookings->cancel($request->user(), $booking, $request->validated()['reason']);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        return response()->json(['booking' => $this->resource($booking)]);
    }

    private function assertOwned(Request $request, Booking $booking): void
    {
        if ($booking->business_id !== $request->user()->business_id) {
            abort(404);
        }
    }

    private function serviceOptions(int $businessId, ?int $branchId): array
    {
        return Service::query()
            ->with(['statusReference', 'category', 'serviceCount'])
            ->where('business_id', $businessId)
            ->whereHas('statusReference', fn ($query) => $query->where('code', 'ACTIVE'))
            ->orderBy('name')
            ->get()
            ->map(function (Service $service) use ($branchId) {
                $today = $this->clock->today();
                $price = ServicePrice::query()
                    ->where('service_id', $service->id)
                    ->whereDate('effective_from', '<=', $today)
                    ->where(function ($query) use ($today) {
                        $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $today);
                    })
                    ->where(function ($query) use ($branchId) {
                        if ($branchId) {
                            $query->where('branch_id', $branchId)->orWhereNull('branch_id');
                        } else {
                            $query->whereNull('branch_id');
                        }
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
                    'duration_minutes' => $service->duration_minutes,
                    'current_price' => $price ? (float) $price->standard_price : null,
                ];
            })
            ->values()
            ->all();
    }

    private function resource(Booking $booking): array
    {
        return [
            'id' => $booking->id,
            'code' => $booking->code,
            'branch' => $booking->branch ? ['id' => $booking->branch->id, 'code' => $booking->branch->code, 'name' => $booking->branch->name] : null,
            'customer' => $booking->customer ? [
                'id' => $booking->customer->id,
                'code' => $booking->customer->code,
                'name' => $booking->customer->full_name,
                'mobile_number' => $booking->customer->mobile_number,
            ] : null,
            'customer_type' => $booking->customer_type,
            'booking_date' => $booking->booking_date?->format('Y-m-d'),
            'booking_time' => substr((string) $booking->booking_time, 0, 5),
            'booking_source' => $booking->booking_source,
            'status' => $booking->status,
            'notes' => $booking->notes,
            'cancellation_reason' => $booking->cancellation_reason,
            'cancelled_at' => $booking->cancelled_at?->toIso8601String(),
            'editable' => $booking->isEditableFromBookings(),
            'can_check_in' => $booking->canCheckIn() && ! $booking->queueEntry,
            'queue_entry' => $booking->queueEntry ? [
                'id' => $booking->queueEntry->id,
                'code' => $booking->queueEntry->code,
                'status' => $booking->queueEntry->status,
            ] : null,
            'services' => $booking->serviceLines->map(fn ($line) => [
                'id' => $line->id,
                'code' => $line->code,
                'service_id' => $line->service_id,
                'service_code' => $line->service?->code,
                'service_name' => $line->service_name_snapshot,
                'source_type' => $line->source_type,
                'quantity' => $line->quantity,
                'assigned_personnel_id' => $line->assigned_personnel_id,
                'assigned_personnel_name' => $line->assignedPersonnel ? trim($line->assignedPersonnel->first_name.' '.$line->assignedPersonnel->last_name) : null,
                'notes' => $line->notes,
            ])->values(),
            'retail_items' => $booking->retailLines->map(fn ($line) => [
                'id' => $line->id,
                'code' => $line->code,
                'item_name' => $line->item_name_snapshot,
                'quantity' => $line->quantity,
                'unit_price' => (float) $line->unit_price_snapshot,
                'notes' => $line->notes,
            ])->values(),
            'rebookings' => $booking->rebookings->map(fn ($row) => [
                'id' => $row->id,
                'code' => $row->code,
                'old_booking_date' => $row->old_booking_date?->format('Y-m-d'),
                'old_booking_time' => substr((string) $row->old_booking_time, 0, 5),
                'new_booking_date' => $row->new_booking_date?->format('Y-m-d'),
                'new_booking_time' => substr((string) $row->new_booking_time, 0, 5),
                'reason' => $row->reason,
                'rebooked_at' => $row->rebooked_at?->toIso8601String(),
            ])->values(),
            'created_at' => $booking->created_at?->toIso8601String(),
            'updated_at' => $booking->updated_at?->toIso8601String(),
        ];
    }
}
