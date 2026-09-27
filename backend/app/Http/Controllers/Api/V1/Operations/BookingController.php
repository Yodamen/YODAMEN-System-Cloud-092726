<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Operations\CancelBookingRequest;
use App\Http\Requests\Api\V1\Operations\RebookBookingRequest;
use App\Http\Requests\Api\V1\Operations\SaveBookingRequest;
use App\Models\AccountingPaymentMethodMapping;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Personnel;
use App\Models\ReferenceGroup;
use App\Models\RetailProduct;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Services\Foundation\BusinessClock;
use App\Services\Foundation\SupportingDocumentService;
use App\Services\Customers\CustomerClassificationService;
use App\Services\Operations\BookingService;
use App\Services\Packages\PackageServiceCreditService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookingController extends Controller
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly \App\Services\Sales\PaymentLedgerService $paymentLedger,
        private readonly PackageServiceCreditService $packages,
        private readonly BusinessClock $clock,
        private readonly CustomerClassificationService $classification,
    ) {
    }

    public function bootstrap(Request $request): JsonResponse
    {
        $user = $request->user();
        $customers = Customer::query()
            ->where('business_id', $user->business_id)
            ->where('status', 'ACTIVE')
            ->orderBy('last_name')->orderBy('first_name')
            ->get(['id', 'code', 'first_name', 'last_name', 'mobile_number']);
        $customerIdentities = $this->classification->identitiesForCustomerIds(
            (int) $user->business_id,
            $customers->pluck('id')->all(),
        );

        return response()->json([
            'customers' => $customers->map(function ($customer) use ($customerIdentities) {
                $identity = $customerIdentities[(int) $customer->id] ?? [];

                return [
                    'id' => $customer->id,
                    'code' => $customer->code,
                    'name' => trim($customer->first_name.' '.$customer->last_name),
                    'mobile_number' => $customer->mobile_number,
                    'customer_type' => $identity['customer_type_code'] ?? 'UNCLASSIFIED',
                    'customer_type_name' => $identity['customer_type'] ?? 'Unclassified',
                    'customer_type_color' => $identity['customer_type_color'] ?? '#8A8A8A',
                    'customer_class' => $identity['customer_class'] ?? 'Unclassified',
                ];
            }),
            'services' => $this->serviceOptions($user->business_id, $user->default_branch_id),
            'retail_products' => $this->retailProductOptions($user->business_id, $user->default_branch_id),
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
            'customer_types' => $this->classification->typeOptions((int) $user->business_id),
            'booking_sources' => [
                ['code' => 'RESERVATION', 'value' => 'Reservation', 'enabled' => true],
                ['code' => 'WALK_IN', 'value' => 'Walk-in', 'enabled' => true, 'note' => 'Created from the Queuing page'],
            ],
            'payment_methods' => $this->paymentMethods($user->business_id),
            'can_receive_advance' => $user->hasPermission('payments', 'CREATE'),
            'can_receive_payment' => $user->hasPermission('payments', 'CREATE'),
            'phase_notes' => [
                'payment_collection' => 'One payment receipt can be split between Advance for the new Booking and Interim settlement of older On Account or Opening Balance amounts.',
                'service_credit' => 'Service Credit claims will be activated with Package/Service Credit tables.',
                'walk_in' => 'Walk-Ins are created from Queuing and atomically create both Booking and Queue records.',
            ],
        ]);
    }


    public function customerServiceCredits(Request $request, Customer $customer): JsonResponse
    {
        if ((int) $customer->business_id !== (int) $request->user()->business_id) abort(404);

        $bookingId = $request->integer('booking_id') ?: null;
        $bookingDate = trim((string) $request->query('booking_date', '')) ?: null;

        return response()->json($this->packages->availableCreditsForCustomer(
            $request->user(),
            (int) $customer->id,
            $bookingId,
            $bookingDate,
        ));
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Booking::query()
            ->with([
                'customer',
                'branch',
                'serviceLines.service',
                'serviceLines.assignedPersonnel',
                'serviceLines.visitServiceLines',
                'retailLines.assignedPersonnel',
                'rebookings',
                'queueEntry',
                'payments',
                'sale.items',
            ])
            ->where('business_id', $user->business_id);

        $view = strtoupper(trim((string) $request->query('view', 'ACTIVE')));
        if ($view === 'ACTIVE') {
            $query->whereNotIn('status', ['COMPLETED', 'CANCELLED']);
        } elseif ($view === 'COMPLETED') {
            $query->where('status', 'COMPLETED')
                ->whereDoesntHave('sale', fn ($saleQuery) => $saleQuery->where('status', 'REVERSED'))
                ->whereDoesntHave('sale.items', fn ($itemQuery) => $itemQuery
                    ->whereIn('correction_status', ['REMOVED', 'REVERSED']));
        } elseif ($view === 'OTHERS') {
            $query->where(function ($otherQuery) {
                $otherQuery->where('status', 'CANCELLED')
                    ->orWhereHas('sale', fn ($saleQuery) => $saleQuery->where('status', 'REVERSED'))
                    ->orWhereHas('sale.items', fn ($itemQuery) => $itemQuery
                        ->whereIn('correction_status', ['REMOVED', 'REVERSED']));
            });
        }

        if ($start = $request->query('start_date')) {
            $query->whereDate('booking_date', '>=', $start);
        }
        if ($end = $request->query('end_date')) {
            $query->whereDate('booking_date', '<=', $end);
        }

        if ($customerName = trim((string) $request->query('customer_name', ''))) {
            $query->whereHas('customer', function ($customerQuery) use ($customerName) {
                $terms = collect(preg_split('/\s+/', $customerName) ?: [])->filter()->values();
                foreach ($terms as $term) {
                    $customerQuery->where(function ($nameQuery) use ($term) {
                        $nameQuery->where('first_name', 'like', "%{$term}%")
                            ->orWhere('last_name', 'like', "%{$term}%");
                    });
                }
            });
        }

        if ($serviceId = (int) $request->query('service_id', 0)) {
            $query->whereHas('serviceLines', fn ($serviceQuery) => $serviceQuery->where('service_id', $serviceId));
        }

        if ($source = strtoupper(trim((string) $request->query('source', '')))) {
            if (in_array($source, ['RESERVATION', 'WALK_IN'], true)) {
                $query->where('booking_source', $source);
            }
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where('code', 'like', "%{$search}%");
        }

        $businessDate = $this->clock->today();
        $rows = $query
            ->orderByRaw('case when booking_date >= ? then 0 else 1 end', [$businessDate])
            ->orderBy('booking_date')
            ->orderBy('booking_time')
            ->get();

        $customerIdentities = $this->classification->identitiesForCustomerIds(
            (int) $user->business_id,
            $rows->pluck('customer_id')->filter()->unique()->all(),
        );
        $bookings = $rows->map(fn (Booking $booking) => $this->resource(
            $booking,
            $customerIdentities[(int) $booking->customer_id] ?? null,
        ));
        $paymentFilter = strtoupper(trim((string) $request->query('payment', '')));
        if ($paymentFilter !== '') {
            $bookings = $bookings->filter(fn (array $booking) => $this->matchesPaymentFilter($booking, $paymentFilter))->values();
        }

        return response()->json(['bookings' => $bookings]);
    }

    public function show(Request $request, Booking $booking): JsonResponse
    {
        $this->assertOwned($request, $booking);
        $booking->load(['customer', 'branch', 'serviceLines.service', 'serviceLines.assignedPersonnel', 'serviceLines.visitServiceLines', 'retailLines.assignedPersonnel', 'rebookings.actor', 'queueEntry', 'payments', 'sale.items']);
        return response()->json(['booking' => $this->resource($booking)]);
    }

    public function store(SaveBookingRequest $request, SupportingDocumentService $documents): JsonResponse
    {
        $payload = $request->validated();
        $collection = is_array($payload['payment_collection'] ?? null)
            ? $payload['payment_collection']
            : (is_array($payload['advance_payment'] ?? null) ? $payload['advance_payment'] : []);

        try {
            if ((bool) ($collection['collected'] ?? false)) {
                $documents->assertPaymentProofForMethods(
                    $request->user(),
                    [$collection['payment_method_reference_id'] ?? null],
                    $collection['attachment_tokens'] ?? [],
                    'Booking payment',
                );
            }

            $booking = DB::transaction(function () use ($request, $payload, $collection, $documents) {
                $booking = $this->bookings->save($request->user(), $payload);
                if ((bool) ($collection['collected'] ?? false)) {
                    $documents->claimTokens(
                        $request->user(),
                        $collection['attachment_tokens'] ?? [],
                        'bookings',
                        'BOOKING_PAYMENT',
                        (int) $booking->id,
                        'BOOKING_PAYMENT_PROOF',
                    );
                }
                return $booking;
            });
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
                $availableSellingUnits = max(0, (int) floor(($availableBasic + 0.00005) / $sellingFactor));

                return [
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'category' => $product->category,
                    'brand' => $product->brand,
                    'unit' => $product->unit,
                    'selling_uom' => $product->selling_uom ?: $product->unit,
                    'selling_to_basic_factor' => $sellingFactor,
                    'selling_price' => $product->sellingPriceForDate(),
                    'available' => $availableSellingUnits,
                    'available_basic' => $availableBasic,
                ];
            })->values()->all();
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


    private function paymentMethods(int $businessId): array
    {
        $ids = AccountingPaymentMethodMapping::query()
            ->where('business_id', $businessId)
            ->where('customer_facing', true)
            ->whereHas('cashAccount', fn ($q) => $q->where('active', true)->where('posting_type', 'POSTING')->where('is_cash_account', true))
            ->pluck('payment_method_reference_id');

        $group = ReferenceGroup::query()->where('business_id', $businessId)->where('code', 'PAYMENT_METHOD')->first();
        if (! $group || $ids->isEmpty()) return [];

        return $group->values()->where('active', true)->whereIn('id', $ids)->orderBy('sort_order')->orderBy('display_value')
            ->get(['id', 'code', 'display_value'])
            ->map(fn ($row) => ['id' => $row->id, 'code' => $row->code, 'name' => $row->display_value])
            ->values()->all();
    }

    private function bookingPaymentStatus(Booking $booking, array $paymentTotals): string
    {
        $sale = $booking->sale;
        if ($sale) {
            $status = strtoupper((string) $sale->status);
            if ($sale->financial_finalized_at) {
                if ($status === 'SERVICE_CREDIT') return 'SERVICE_CREDIT';
                if ($status === 'PAID' || (float) $sale->outstanding_balance <= 0.009) return 'PAID';
                if ($status === 'ON_ACCOUNT' || (float) $sale->outstanding_balance > 0.009) return 'ON_ACCOUNT';
            }

            if (in_array($status, ['FOR_CHECKOUT', 'PARTIALLY_PAID', 'AWAITING_CONFIRM'], true)) {
                return 'PENDING_CHECKOUT';
            }
        }

        if ((float) ($paymentTotals['checkout'] ?? 0) > 0.009) return 'CHECKOUT';
        if ((float) ($paymentTotals['advance'] ?? 0) > 0.009 && (float) ($paymentTotals['interim'] ?? 0) > 0.009) return 'ADVANCE_INTERIM';
        if ((float) ($paymentTotals['advance'] ?? 0) > 0.009) return 'ADVANCE';
        if ((float) ($paymentTotals['interim'] ?? 0) > 0.009) return 'INTERIM';

        return 'UNPAID';
    }

    private function bookingPaymentType(Booking $booking, string $paymentStatus, float $grossCollected): string
    {
        $booking->loadMissing('sale.items');

        $hasServiceCredit = $booking->sale?->items
            ->contains(fn ($item) => strtoupper((string) $item->item_type) === 'SERVICE_CREDIT_USE') ?? false;

        if (strtoupper((string) $booking->status) === 'COMPLETED' && $hasServiceCredit) {
            return $grossCollected > 0.009 ? 'PAID_SERVICE_CREDIT' : 'SERVICE_CREDIT';
        }

        return $paymentStatus;
    }

    private function matchesPaymentFilter(array $booking, string $paymentFilter): bool
    {
        return match ($paymentFilter) {
            'ADVANCE' => (float) ($booking['advance_payment_total'] ?? 0) > 0.009,
            'INTERIM' => (float) ($booking['interim_payment_total'] ?? 0) > 0.009,
            'CHECKOUT' => (float) ($booking['checkout_payment_total'] ?? 0) > 0.009,
            'PAID_SERVICE_CREDIT' => strtoupper((string) ($booking['payment_type'] ?? '')) === 'PAID_SERVICE_CREDIT',
            'SERVICE_CREDIT' => strtoupper((string) ($booking['payment_type'] ?? '')) === 'SERVICE_CREDIT',
            'PAID', 'ON_ACCOUNT', 'PENDING_CHECKOUT', 'UNPAID' => strtoupper((string) ($booking['payment_status'] ?? '')) === $paymentFilter,
            default => true,
        };
    }

    private function resource(Booking $booking, ?array $customerIdentity = null): array
    {
        $booking->loadMissing(['sale.items', 'payments']);
        $paymentTotals = $this->paymentLedger->bookingAllocationTotals($booking);
        $paymentStatus = $this->bookingPaymentStatus($booking, $paymentTotals);
        $grossCollected = round((float) $booking->payments
            ->filter(fn ($payment) => strtoupper((string) $payment->status) === 'RECEIVED')
            ->sum('amount'), 2);
        $paymentType = $this->bookingPaymentType($booking, $paymentStatus, $grossCollected);
        $netAfterDiscount = $booking->sale
            ? round((float) ($booking->sale->net_amount ?? $booking->sale->gross_amount), 2)
            : 0.0;

        $saleItems = $booking->sale?->items ?? collect();
        $hasRemovedOrReversedLine = $saleItems->contains(fn ($item) => in_array(
            strtoupper((string) ($item->correction_status ?? 'ACTIVE')),
            ['REMOVED', 'REVERSED'],
            true,
        ));
        $saleItemsByVisitServiceLine = $saleItems
            ->filter(fn ($item) => $item->visit_service_line_id !== null)
            ->keyBy('visit_service_line_id');
        $bookingServiceCorrectionStatus = function ($line) use ($saleItemsByVisitServiceLine): string {
            foreach ($line->visitServiceLines as $visitServiceLine) {
                $status = strtoupper((string) ($saleItemsByVisitServiceLine->get($visitServiceLine->id)?->correction_status ?? 'ACTIVE'));
                if (in_array($status, ['REMOVED', 'REVERSED'], true)) return $status;
                if ($status === 'CORRECTED') return 'CORRECTED';
            }
            return 'ACTIVE';
        };

        $effectiveStatus = strtoupper((string) ($booking->sale?->status ?? '')) === 'REVERSED'
            ? 'REVERSED'
            : ($hasRemovedOrReversedLine ? 'CORRECTED' : $booking->status);

        return [
            'id' => $booking->id,
            'code' => $booking->code,
            'branch' => $booking->branch ? ['id' => $booking->branch->id, 'code' => $booking->branch->code, 'name' => $booking->branch->name] : null,
            'customer' => $booking->customer ? (function () use ($booking, $customerIdentity) {
                $identity = $customerIdentity ?? $this->classification->identityForCustomer($booking->customer);

                return [
                    'id' => $booking->customer->id,
                    'code' => $booking->customer->code,
                    'name' => $booking->customer->full_name,
                    'mobile_number' => $booking->customer->mobile_number,
                    'segment' => $identity['customer_type'],
                    'customer_type' => $identity['customer_type'],
                    'customer_type_color' => $identity['customer_type_color'],
                    'customer_class' => $identity['customer_class'],
                ];
            })() : null,
            'customer_type' => $booking->customer_type,
            'booking_date' => $booking->booking_date?->format('Y-m-d'),
            'booking_time' => substr((string) $booking->booking_time, 0, 5),
            'booking_source' => $booking->booking_source,
            'status' => $effectiveStatus,
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
            'advance_payment_total' => $paymentTotals['advance'],
            'interim_payment_total' => $paymentTotals['interim'],
            'checkout_payment_total' => $paymentTotals['checkout'] ?? 0.0,
            'gross_collected_total' => $grossCollected,
            'collected_received_total' => $booking->sale
                ? round((float) $booking->sale->applied_amount, 2)
                : 0.0,
            'net_after_discount_total' => $netAfterDiscount,
            'payment_status' => $paymentStatus,
            'payment_type' => $paymentType,
            'services' => $booking->serviceLines->map(fn ($line) => [
                'id' => $line->id,
                'code' => $line->code,
                'service_id' => $line->service_id,
                'service_code' => $line->service?->code,
                'service_name' => $line->service_name_snapshot,
                'source_type' => $line->source_type,
                'source_reference_code' => $line->source_reference_code,
                'package_enrollment_id' => $line->package_enrollment_id,
                'service_credit_id' => $line->service_credit_id,
                'quantity' => $line->quantity,
                'assigned_personnel_id' => $line->assigned_personnel_id,
                'assigned_personnel_name' => $line->assignedPersonnel ? trim($line->assignedPersonnel->first_name.' '.$line->assignedPersonnel->last_name) : null,
                'correction_status' => $bookingServiceCorrectionStatus($line),
                'notes' => $line->notes,
            ])->values(),
            'retail_items' => $booking->retailLines->map(fn ($line) => [
                'id' => $line->id,
                'code' => $line->code,
                'retail_product_id' => $line->retail_product_id,
                'sku' => $line->sku_snapshot,
                'item_name' => $line->item_name_snapshot,
                'quantity' => $line->quantity,
                'unit_price' => (float) $line->unit_price_snapshot,
                'assigned_personnel_id' => $line->assigned_personnel_id,
                'assigned_personnel_name' => $line->assigned_personnel_name_snapshot ?: ($line->assignedPersonnel ? trim($line->assignedPersonnel->first_name.' '.$line->assignedPersonnel->last_name) : null),
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
