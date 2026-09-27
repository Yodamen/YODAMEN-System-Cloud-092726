<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Operations\CancelQueueRequest;
use App\Http\Requests\Api\V1\Operations\SaveWalkInRequest;
use App\Models\AccountingPaymentMethodMapping;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Personnel;
use App\Models\QueueEntry;
use App\Models\ReferenceGroup;
use App\Models\RetailProduct;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Services\Foundation\BusinessClock;
use App\Services\Foundation\SupportingDocumentService;
use App\Services\Customers\CustomerClassificationService;
use App\Services\Operations\QueueService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QueueController extends Controller
{
    public function __construct(
        private readonly QueueService $queue,
        private readonly \App\Services\Sales\PaymentLedgerService $paymentLedger,
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
            'business_date' => $this->clock->today(),
            'customers' => $customers->map(function ($row) use ($customerIdentities) {
                $identity = $customerIdentities[(int) $row->id] ?? [];

                return [
                    'id' => $row->id,
                    'code' => $row->code,
                    'name' => trim($row->first_name.' '.$row->last_name),
                    'mobile_number' => $row->mobile_number,
                    'customer_type' => $identity['customer_type_code'] ?? 'UNCLASSIFIED',
                    'customer_type_name' => $identity['customer_type'] ?? 'Unclassified',
                    'customer_type_color' => $identity['customer_type_color'] ?? '#8A8A8A',
                    'customer_class' => $identity['customer_class'] ?? 'Unclassified',
                ];
            }),
            'services' => $this->serviceOptions($user->business_id, $user->default_branch_id),
            'retail_products' => $this->retailProductOptions($user->business_id, $user->default_branch_id),
            'personnel' => Personnel::query()->where('business_id', $user->business_id)->where('status', 'ACTIVE')->where('can_perform_services', true)
                ->orderBy('last_name')->orderBy('first_name')->get(['id', 'code', 'first_name', 'last_name'])
                ->map(fn ($row) => ['id' => $row->id, 'code' => $row->code, 'name' => trim($row->first_name.' '.$row->last_name)]),
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
            'payment_methods' => $this->paymentMethods($user->business_id),
            'can_receive_advance' => $user->hasPermission('payments', 'CREATE'),
            'can_receive_payment' => $user->hasPermission('payments', 'CREATE'),
            'phase_notes' => [
                'payment_collection' => 'One payment receipt can be split between Advance for the new Walk-In and Interim settlement of older On Account or Opening Balance amounts.',
                'service_credit' => 'Service Credit claims activate with Package Enrollment and Service Credit tables.',
                'servicing' => 'Start Servicing now creates one Visit from the waiting Queue entry. Financial finalization remains separate.',
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $date = (string) $request->query('date', $this->clock->today());
        $status = strtoupper((string) $request->query('status', 'WAITING'));
        if (! in_array($status, ['WAITING', 'SERVICING', 'COMPLETED', 'CANCELLED', 'ALL'], true)) $status = 'WAITING';

        $rows = $this->queue->entriesForDate($request->user(), $date, $status);
        $customerIdentities = $this->classification->identitiesForCustomerIds(
            (int) $request->user()->business_id,
            $rows->pluck('customer_id')->filter()->unique()->all(),
        );

        return response()->json(['queue' => $rows->map(fn (QueueEntry $row) => $this->resource(
            $row,
            $customerIdentities[(int) $row->customer_id] ?? null,
        ))->values()]);
    }

    public function show(Request $request, QueueEntry $queueEntry): JsonResponse
    {
        $this->assertOwned($request, $queueEntry);
        $queueEntry->load(['customer', 'booking.payments', 'branch', 'visit', 'serviceLines.service', 'serviceLines.assignedPersonnel', 'retailLines.assignedPersonnel']);
        return response()->json(['queue_entry' => $this->resource($queueEntry)]);
    }

    public function checkIn(Request $request, Booking $booking): JsonResponse
    {
        try { $queue = $this->queue->checkInBooking($request->user(), $booking); }
        catch (DomainException $e) { return response()->json(['message' => $e->getMessage()], 422); }
        return response()->json(['queue_entry' => $this->resource($queue)]);
    }

    public function createWalkIn(SaveWalkInRequest $request, SupportingDocumentService $documents): JsonResponse
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
                    'Walk-In payment',
                );
            }

            $queue = DB::transaction(function () use ($request, $payload, $collection, $documents) {
                $queue = $this->queue->createWalkIn($request->user(), $payload);
                if ((bool) ($collection['collected'] ?? false) && $queue->booking_id) {
                    $documents->claimTokens(
                        $request->user(),
                        $collection['attachment_tokens'] ?? [],
                        'bookings',
                        'BOOKING_PAYMENT',
                        (int) $queue->booking_id,
                        'BOOKING_PAYMENT_PROOF',
                    );
                }
                return $queue;
            });
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['queue_entry' => $this->resource($queue)], 201);
    }

    public function cancel(CancelQueueRequest $request, QueueEntry $queueEntry): JsonResponse
    {
        try { $queue = $this->queue->cancel($request->user(), $queueEntry, $request->validated()['reason']); }
        catch (DomainException $e) { return response()->json(['message' => $e->getMessage()], 422); }
        return response()->json(['queue_entry' => $this->resource($queue)]);
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
        return Service::query()->with(['statusReference', 'category', 'serviceCount'])->where('business_id', $businessId)
            ->whereHas('statusReference', fn ($q) => $q->where('code', 'ACTIVE'))->orderBy('name')->get()
            ->map(function (Service $service) use ($branchId) {
                $today = $this->clock->today();
                $price = ServicePrice::query()->where('service_id', $service->id)->whereDate('effective_from', '<=', $today)
                    ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $today))
                    ->where(function ($q) use ($branchId) {
                        $branchId ? $q->where('branch_id', $branchId)->orWhereNull('branch_id') : $q->whereNull('branch_id');
                    })->orderByRaw('branch_id is null')->orderByDesc('effective_from')->first();
                return ['id' => $service->id, 'code' => $service->code, 'name' => $service->name,
                    'category' => $service->category?->display_value,
                    'service_count' => $service->serviceCount?->code,
                    'number_of_sessions' => $service->number_of_sessions,
                    'current_price' => $price ? (float) $price->standard_price : null];
            })->values()->all();
    }

    private function resource(QueueEntry $row, ?array $customerIdentity = null): array
    {
        $paymentTotals = $row->booking ? $this->paymentLedger->bookingAllocationTotals($row->booking) : ['advance' => 0.0, 'interim' => 0.0];

        return [
            'id' => $row->id, 'code' => $row->code, 'priority_no' => $row->getAttribute('priority_no'),
            'booking' => $row->booking ? ['id' => $row->booking->id, 'code' => $row->booking->code, 'status' => $row->booking->status] : null,
            'advance_payment_total' => $paymentTotals['advance'],
            'interim_payment_total' => $paymentTotals['interim'],
            'customer' => $row->customer ? (function () use ($row, $customerIdentity) {
                $identity = $customerIdentity ?? $this->classification->identityForCustomer($row->customer);

                return [
                    'id' => $row->customer->id,
                    'code' => $row->customer->code,
                    'name' => trim($row->customer->first_name.' '.$row->customer->last_name),
                    'mobile_number' => $row->customer->mobile_number,
                    'segment' => $identity['customer_type'],
                    'customer_type' => $identity['customer_type'],
                    'customer_type_color' => $identity['customer_type_color'],
                    'customer_class' => $identity['customer_class'],
                ];
            })() : null,
            'branch' => $row->branch ? ['id' => $row->branch->id, 'code' => $row->branch->code, 'name' => $row->branch->name] : null,
            'customer_type' => $row->customer_type, 'queue_date' => $row->queue_date?->format('Y-m-d'),
            'check_in_at' => $row->check_in_at?->toIso8601String(), 'check_in_time' => $row->check_in_at?->format('H:i'),
            'source' => $row->source, 'status' => $row->status,
            'visit' => $row->visit ? ['id' => $row->visit->id, 'code' => $row->visit->code, 'status' => $row->visit->status] : null,
            'scheduled_date' => $row->scheduled_date?->format('Y-m-d'), 'scheduled_time' => $row->scheduled_time ? substr((string) $row->scheduled_time, 0, 5) : null,
            'notes' => $row->notes, 'cancellation_reason' => $row->cancellation_reason,
            'services' => $row->serviceLines->map(fn ($line) => [
                'id' => $line->id, 'code' => $line->code, 'service_id' => $line->service_id,
                'service_name' => $line->service_name_snapshot, 'source_type' => $line->source_type, 'quantity' => $line->quantity,
                'assigned_personnel_id' => $line->assigned_personnel_id,
                'assigned_personnel_name' => $line->assigned_personnel_name_snapshot, 'status' => $line->status, 'notes' => $line->notes,
            ])->values(),
            'retail_items' => $row->retailLines->map(fn ($line) => [
                'id' => $line->id, 'code' => $line->code, 'retail_product_id' => $line->retail_product_id, 'sku' => $line->sku_snapshot, 'item_name' => $line->item_name_snapshot,
                'quantity' => $line->quantity, 'unit_price' => (float) $line->unit_price_snapshot,
                'assigned_personnel_id' => $line->assigned_personnel_id,
                'assigned_personnel_name' => $line->assigned_personnel_name_snapshot ?: ($line->assignedPersonnel ? trim($line->assignedPersonnel->first_name.' '.$line->assignedPersonnel->last_name) : null),
                'notes' => $line->notes,
            ])->values(),
            'can_cancel' => $row->isWaiting(),
            'can_start_servicing' => $row->isWaiting() && ! $row->visit,
        ];
    }

    private function assertOwned(Request $request, QueueEntry $queue): void
    {
        if ($queue->business_id !== $request->user()->business_id) abort(404);
    }
}
