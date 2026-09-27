<?php

namespace App\Services\Operations;

use App\Models\Booking;
use App\Models\BookingRebooking;
use App\Models\Customer;
use App\Models\InventoryStock;
use App\Models\Personnel;
use App\Models\RetailProduct;
use App\Models\Service;
use App\Models\User;
use App\Services\Foundation\BusinessClock;
use App\Services\Customers\CustomerClassificationService;
use App\Services\Foundation\CodeSequenceService;
use App\Services\Packages\PackageServiceCreditService;
use App\Services\Security\AuditService;
use App\Services\Sales\CheckoutSettlementService;
use App\Services\Sales\PaymentLedgerService;
use DomainException;
use Illuminate\Support\Facades\DB;

class BookingService
{
    public function __construct(
        private readonly CodeSequenceService $codes,
        private readonly AuditService $audit,
        private readonly BusinessClock $clock,
        private readonly PaymentLedgerService $payments,
        private readonly CheckoutSettlementService $settlements,
        private readonly PackageServiceCreditService $packages,
        private readonly CustomerClassificationService $classification,
    ) {
    }

    public function save(User $actor, array $data, ?Booking $booking = null): Booking
    {
        if ($booking && $booking->business_id !== $actor->business_id) {
            abort(404);
        }

        return DB::transaction(function () use ($actor, $data, $booking) {
            if ($booking && ! $booking->isEditableFromBookings()) {
                throw new DomainException('This booking is no longer editable from Bookings. Once checked in, manage it from Queuing or Servicing.');
            }

            $customer = Customer::query()
                ->where('business_id', $actor->business_id)
                ->whereKey($data['customer_id'])
                ->firstOrFail();

            if (strtoupper($customer->status) !== 'ACTIVE') {
                throw new DomainException('Inactive customers cannot be used for a new or edited booking.');
            }

            // Bookings created from this workflow are Reservations by definition.
            // Source is system-derived and cannot be overridden by the browser/API.
            if (filled($data['booking_source'] ?? null) && strtoupper((string) $data['booking_source']) !== 'RESERVATION') {
                throw new DomainException('Booking Source is automatically detected by the workflow. Create Walk-Ins from Queuing.');
            }
            $source = 'RESERVATION';
            $completedVisits = $customer->visits()->where('status', 'COMPLETED')->count();
            $customerType = $this->classification->typeFor((int) $actor->business_id, $completedVisits)['code'];

            $branchId = $booking?->branch_id ?? $actor->default_branch_id;
            if (! $branchId) {
                throw new DomainException('A default branch is required before creating bookings.');
            }

            $validatedServices = $this->validateServiceLines(
                $actor,
                $customer,
                $data['services'] ?? [],
                $booking?->id,
                $data['booking_date'],
            );
            $validatedRetail = $this->validateRetailLines($actor, $data['retail_items'] ?? [], (int) $branchId);

            if (! $validatedServices && ! $validatedRetail) {
                throw new DomainException('Add at least one service or retail purchase.');
            }

            $before = $booking ? $this->snapshot($booking->load(['customer', 'serviceLines.assignedPersonnel', 'retailLines.assignedPersonnel'])) : null;
            $isNew = ! $booking;

            if (! $booking) {
                $date = \Carbon\CarbonImmutable::parse($data['booking_date'], $this->clock->timezone());
                $prefix = 'BKG-'.$date->format('my').'-';
                $booking = new Booking([
                    'business_id' => $actor->business_id,
                    'branch_id' => $branchId,
                    'code' => $this->codes->next('BOOKING:'.$date->format('my'), $prefix, 6, $actor->business_id),
                    'status' => 'BOOKED',
                    'created_by' => $actor->id,
                ]);
            }

            $booking->fill([
                'customer_id' => $customer->id,
                'customer_type' => $customerType,
                'booking_date' => $data['booking_date'],
                'booking_time' => $data['booking_time'].':00',
                'booking_source' => $source,
                'notes' => $this->nullableTrim($data['notes'] ?? null),
                'updated_by' => $actor->id,
            ]);
            $booking->save();

            if (! $isNew) {
                $this->packages->cancelPendingForBooking($actor, $booking, 'Booking services edited before package checkout.');
            }

            $booking->serviceLines()->delete();
            foreach ($validatedServices as $index => $line) {
                $sourceType = $line['source_type'];
                $bookingLine = $booking->serviceLines()->create([
                    'code' => $booking->code.'-S'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                    'service_id' => $line['service']->id,
                    'service_name_snapshot' => $line['service']->name,
                    'source_type' => $sourceType,
                    'source_reference_code' => $line['source_reference_code'],
                    'package_enrollment_id' => $line['package_enrollment_id'],
                    'service_credit_id' => $line['service_credit_id'],
                    'quantity' => $sourceType === 'SERVICE_CREDIT' ? 1 : $this->packages->operationalQuantity($line['service'], $line['quantity']),
                    'assigned_personnel_id' => $line['personnel']?->id,
                    'notes' => $line['notes'],
                    'sort_order' => ($index + 1) * 10,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);

                if ($sourceType === 'NEW_PACKAGE') {
                    $this->packages->ensurePendingEnrollment($actor, $booking, $bookingLine);
                }
            }

            $booking->retailLines()->delete();
            foreach ($validatedRetail as $index => $line) {
                $booking->retailLines()->create([
                    'code' => $booking->code.'-R'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                    'legacy_retail_item_code' => null,
                    'retail_product_id' => $line['retail_product_id'],
                    'sku_snapshot' => $line['sku'],
                    'item_name_snapshot' => $line['item_name'],
                    'quantity' => $line['quantity'],
                    'unit_price_snapshot' => $line['unit_price'],
                    'assigned_personnel_id' => $line['personnel']?->id,
                    'assigned_personnel_name_snapshot' => $line['personnel'] ? trim($line['personnel']->first_name.' '.$line['personnel']->last_name) : null,
                    'notes' => $line['notes'],
                    'sort_order' => ($index + 1) * 10,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);
            }

            $booking->refresh()->load(['customer', 'branch', 'serviceLines.service', 'serviceLines.assignedPersonnel', 'retailLines.assignedPersonnel', 'rebookings']);

            $this->audit->record(
                action: $isNew ? 'CREATE' : 'EDIT',
                module: 'bookings',
                entityType: Booking::class,
                entityId: $booking->id,
                before: $before,
                after: $this->snapshot($booking),
                actor: $actor,
            );

            if ($isNew) {
                $this->recordInitialBookingPayment($actor, $booking, $data);
            }

            return $booking;
        });
    }

    public function rebook(User $actor, Booking $booking, array $data): Booking
    {
        $this->assertOwnedAndEditable($actor, $booking);

        return DB::transaction(function () use ($actor, $booking, $data) {
            $booking = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $this->assertOwnedAndEditable($actor, $booking);

            $before = $this->snapshot($booking->load(['customer', 'serviceLines.assignedPersonnel', 'retailLines.assignedPersonnel']));
            $oldDate = $booking->booking_date->format('Y-m-d');
            $oldTime = substr((string) $booking->booking_time, 0, 5);
            $newDate = $data['booking_date'];
            $newTime = $data['booking_time'];
            $this->packages->assertBookingCreditsValidForDate($actor, $booking, $newDate);
            $now = $this->clock->now();

            $prefix = 'RBK-'.$now->format('my').'-';
            BookingRebooking::query()->create([
                'business_id' => $actor->business_id,
                'booking_id' => $booking->id,
                'code' => $this->codes->next('REBOOKING:'.$now->format('my'), $prefix, 6, $actor->business_id),
                'old_booking_date' => $oldDate,
                'old_booking_time' => $oldTime.':00',
                'new_booking_date' => $newDate,
                'new_booking_time' => $newTime.':00',
                'reason' => $this->nullableTrim($data['reason'] ?? null),
                'rebooked_by' => $actor->id,
                'rebooked_at' => $now,
            ]);

            $booking->update([
                'booking_date' => $newDate,
                'booking_time' => $newTime.':00',
                'updated_by' => $actor->id,
            ]);
            $booking->refresh()->load(['customer', 'branch', 'serviceLines.service', 'serviceLines.assignedPersonnel', 'retailLines.assignedPersonnel', 'rebookings']);

            $this->audit->record(
                action: 'REBOOK',
                module: 'bookings',
                entityType: Booking::class,
                entityId: $booking->id,
                before: $before,
                after: $this->snapshot($booking),
                reason: $data['reason'] ?? null,
                actor: $actor,
            );

            return $booking;
        });
    }

    public function cancel(User $actor, Booking $booking, string $reason): Booking
    {
        $this->assertOwnedAndEditable($actor, $booking);

        return DB::transaction(function () use ($actor, $booking, $reason) {
            $booking = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $this->assertOwnedAndEditable($actor, $booking);
            $booking->load(['customer', 'serviceLines.assignedPersonnel', 'retailLines.assignedPersonnel']);
            $before = $this->snapshot($booking);

            $booking->update([
                'status' => 'CANCELLED',
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancellation_reason' => trim($reason),
                'updated_by' => $actor->id,
            ]);
            $this->packages->cancelPendingForBooking($actor, $booking, $reason);
            $booking->refresh()->load(['customer', 'branch', 'serviceLines.service', 'serviceLines.assignedPersonnel', 'retailLines.assignedPersonnel', 'rebookings']);

            $this->audit->record(
                action: 'CANCEL',
                module: 'bookings',
                entityType: Booking::class,
                entityId: $booking->id,
                before: $before,
                after: $this->snapshot($booking),
                reason: $reason,
                actor: $actor,
            );

            return $booking;
        });
    }

    public function createWalkIn(User $actor, array $data): Booking
    {
        return DB::transaction(function () use ($actor, $data) {
            $customer = Customer::query()
                ->where('business_id', $actor->business_id)
                ->whereKey($data['customer_id'])
                ->firstOrFail();

            if (strtoupper($customer->status) !== 'ACTIVE') {
                throw new DomainException('Inactive customers cannot be used for a Walk-In.');
            }

            $completedVisits = $customer->visits()->where('status', 'COMPLETED')->count();
            $customerType = $this->classification->typeFor((int) $actor->business_id, $completedVisits)['code'];

            if (! $actor->default_branch_id) {
                throw new DomainException('A default branch is required before creating a Walk-In.');
            }

            $validatedServices = $this->validateServiceLines(
                $actor,
                $customer,
                $data['services'] ?? [],
                null,
                $this->clock->today(),
            );
            $validatedRetail = $this->validateRetailLines($actor, $data['retail_items'] ?? [], (int) $actor->default_branch_id);

            if (! $validatedServices && ! $validatedRetail) {
                throw new DomainException('Add at least one service or retail purchase.');
            }

            $now = $this->clock->now();
            $prefix = 'BKG-'.$now->format('my').'-';
            $booking = Booking::query()->create([
                'business_id' => $actor->business_id,
                'branch_id' => $actor->default_branch_id,
                'code' => $this->codes->next('BOOKING:'.$now->format('my'), $prefix, 6, $actor->business_id),
                'customer_id' => $customer->id,
                'customer_type' => $customerType,
                'booking_date' => $now->toDateString(),
                'booking_time' => $now->format('H:i:s'),
                'booking_source' => 'WALK_IN',
                'status' => 'CHECKED_IN',
                'notes' => $this->nullableTrim($data['notes'] ?? null),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            foreach ($validatedServices as $index => $line) {
                $sourceType = $line['source_type'];
                $bookingLine = $booking->serviceLines()->create([
                    'code' => $booking->code.'-S'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                    'service_id' => $line['service']->id,
                    'service_name_snapshot' => $line['service']->name,
                    'source_type' => $sourceType,
                    'source_reference_code' => $line['source_reference_code'],
                    'package_enrollment_id' => $line['package_enrollment_id'],
                    'service_credit_id' => $line['service_credit_id'],
                    'quantity' => $sourceType === 'SERVICE_CREDIT' ? 1 : $this->packages->operationalQuantity($line['service'], $line['quantity']),
                    'assigned_personnel_id' => $line['personnel']?->id,
                    'notes' => $line['notes'],
                    'sort_order' => ($index + 1) * 10,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);

                if ($sourceType === 'NEW_PACKAGE') {
                    $this->packages->ensurePendingEnrollment($actor, $booking, $bookingLine);
                }
            }

            foreach ($validatedRetail as $index => $line) {
                $booking->retailLines()->create([
                    'code' => $booking->code.'-R'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                    'legacy_retail_item_code' => null,
                    'retail_product_id' => $line['retail_product_id'],
                    'sku_snapshot' => $line['sku'],
                    'item_name_snapshot' => $line['item_name'],
                    'quantity' => $line['quantity'],
                    'unit_price_snapshot' => $line['unit_price'],
                    'assigned_personnel_id' => $line['personnel']?->id,
                    'assigned_personnel_name_snapshot' => $line['personnel'] ? trim($line['personnel']->first_name.' '.$line['personnel']->last_name) : null,
                    'notes' => $line['notes'],
                    'sort_order' => ($index + 1) * 10,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);
            }

            $booking->refresh()->load(['customer', 'branch', 'serviceLines.service', 'serviceLines.assignedPersonnel', 'retailLines.assignedPersonnel', 'rebookings']);

            $this->audit->record(
                action: 'CREATE_WALK_IN',
                module: 'bookings',
                entityType: Booking::class,
                entityId: $booking->id,
                after: $this->snapshot($booking),
                actor: $actor,
                metadata: ['source' => 'WALK_IN'],
            );

            $this->recordInitialBookingPayment($actor, $booking, $data);

            return $booking;
        });
    }


    private function recordInitialBookingPayment(User $actor, Booking $booking, array $data): void
    {
        $collection = $data['payment_collection'] ?? null;

        // Backward compatibility for Phase 3A/early Phase 4 clients and historical tests.
        if (! is_array($collection) && isset($data['advance_payment'])) {
            $this->payments->recordInitialAdvance($actor, $booking, $data['advance_payment']);
            return;
        }

        if (! is_array($collection) || ! ($collection['collected'] ?? false)) {
            return;
        }

        $this->settlements->receiveBookingPaymentReceipt($actor, $booking, $collection);
    }

    public function snapshot(Booking $booking): array
    {
        return [
            'id' => $booking->id,
            'code' => $booking->code,
            'customer_id' => $booking->customer_id,
            'customer_code' => $booking->customer?->code,
            'customer_type' => $booking->customer_type,
            'booking_date' => $booking->booking_date?->format('Y-m-d'),
            'booking_time' => substr((string) $booking->booking_time, 0, 5),
            'booking_source' => $booking->booking_source,
            'status' => $booking->status,
            'notes' => $booking->notes,
            'cancellation_reason' => $booking->cancellation_reason,
            'services' => $booking->serviceLines->map(fn ($line) => [
                'code' => $line->code,
                'service_id' => $line->service_id,
                'service_name' => $line->service_name_snapshot,
                'source_type' => $line->source_type,
                'source_reference_code' => $line->source_reference_code,
                'package_enrollment_id' => $line->package_enrollment_id,
                'service_credit_id' => $line->service_credit_id,
                'quantity' => $line->quantity,
                'assigned_personnel_id' => $line->assigned_personnel_id,
            ])->values()->all(),
            'retail_items' => $booking->retailLines->map(fn ($line) => [
                'code' => $line->code,
                'item_name' => $line->item_name_snapshot,
                'quantity' => $line->quantity,
                'unit_price' => (float) $line->unit_price_snapshot,
                'assigned_personnel_id' => $line->assigned_personnel_id,
                'assigned_personnel_name' => $line->assigned_personnel_name_snapshot ?: ($line->assignedPersonnel ? trim($line->assignedPersonnel->first_name.' '.$line->assignedPersonnel->last_name) : null),
            ])->values()->all(),
        ];
    }

    private function validateServiceLines(
        User $actor,
        Customer $customer,
        array $lines,
        ?int $excludeBookingId = null,
        ?string $forDate = null,
    ): array {
        $result = [];
        $requestedCredits = [];

        foreach ($lines as $line) {
            $service = Service::query()
                ->with(['statusReference', 'serviceCount'])
                ->where('business_id', $actor->business_id)
                ->whereKey($line['service_id'])
                ->firstOrFail();

            if (strtoupper((string) $service->statusReference?->code) !== 'ACTIVE') {
                throw new DomainException('Inactive services cannot be added to a booking.');
            }

            $sourceType = $this->packages->sourceTypeForNewService($service);
            $sourceReferenceCode = null;
            $packageEnrollmentId = null;
            $serviceCreditId = null;
            $quantity = max(1, (int) ($line['quantity'] ?? 1));

            if (! empty($line['service_credit_id'])) {
                $resolved = $this->packages->resolveCreditForBookingLine(
                    $actor,
                    (int) $customer->id,
                    (int) $line['service_credit_id'],
                    $excludeBookingId,
                    $forDate,
                );
                $credit = $resolved['credit'];

                if ((int) $credit->service_id !== (int) $service->id) {
                    throw new DomainException('The selected Service Credit does not match the selected service.');
                }

                $requestedCredits[$credit->id] = ($requestedCredits[$credit->id] ?? 0) + 1;
                if ($requestedCredits[$credit->id] > (int) $resolved['available_sessions']) {
                    throw new DomainException('The selected Service Credit does not have enough available sessions for this booking.');
                }

                $sourceType = 'SERVICE_CREDIT';
                $sourceReferenceCode = $credit->code;
                $packageEnrollmentId = $credit->package_enrollment_id;
                $serviceCreditId = $credit->id;
                $quantity = 1;
            }

            $personnel = null;
            if (! empty($line['assigned_personnel_id'])) {
                $personnel = Personnel::query()
                    ->where('business_id', $actor->business_id)
                    ->whereKey($line['assigned_personnel_id'])
                    ->firstOrFail();

                if (strtoupper($personnel->status) !== 'ACTIVE' || ! $personnel->can_perform_services) {
                    throw new DomainException('The assigned personnel is not currently available for service assignment.');
                }
            }

            $result[] = [
                'service' => $service,
                'source_type' => $sourceType,
                'source_reference_code' => $sourceReferenceCode,
                'package_enrollment_id' => $packageEnrollmentId,
                'service_credit_id' => $serviceCreditId,
                'quantity' => $quantity,
                'personnel' => $personnel,
                'notes' => $this->nullableTrim($line['notes'] ?? null),
            ];
        }

        return $result;
    }

    private function validateRetailLines(User $actor, array $lines, int $branchId): array
    {
        $result = [];
        $requestedSellingUnits = [];
        $productsById = [];

        foreach ($lines as $line) {
            $product = null;
            if (! empty($line['retail_product_id'])) {
                $product = RetailProduct::query()
                    ->where('business_id', $actor->business_id)
                    ->where('status', 'ACTIVE')
                    ->where('stock_type', 'INVENTORY')
                    ->where('retail_available', true)
                    ->whereKey((int) $line['retail_product_id'])
                    ->first();

                if (! $product) {
                    throw new DomainException('Select an active Inventory Masterlist item for each retail purchase.');
                }
            }

            $name = $product?->name ?: trim((string) ($line['item_name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $quantity = max(1, (int) ($line['quantity'] ?? 1));
            if ($product) {
                $productId = (int) $product->id;
                $requestedSellingUnits[$productId] = ($requestedSellingUnits[$productId] ?? 0) + $quantity;
                $productsById[$productId] = $product;
            }

            $personnel = null;
            if (! empty($line['assigned_personnel_id'])) {
                $personnel = Personnel::query()
                    ->where('business_id', $actor->business_id)
                    ->whereKey((int) $line['assigned_personnel_id'])
                    ->firstOrFail();

                if (strtoupper((string) $personnel->status) !== 'ACTIVE' || ! $personnel->commission_eligible) {
                    throw new DomainException('Retail Assigned Staff must be active and commission eligible.');
                }
            }

            $result[] = [
                'product' => $product,
                'retail_product_id' => $product?->id,
                'sku' => $product?->sku,
                'item_name' => $name,
                'quantity' => $quantity,
                // Inventory Masterlist selling price is authoritative. Legacy free-text lines keep their historical submitted price.
                'unit_price' => round($product ? $product->sellingPriceForDate() : max(0, (float) ($line['unit_price'] ?? 0)), 2),
                'personnel' => $personnel,
                'notes' => $this->nullableTrim($line['notes'] ?? null),
            ];
        }

        foreach ($requestedSellingUnits as $productId => $sellingUnits) {
            $product = $productsById[$productId];
            $stock = InventoryStock::query()
                ->where('business_id', $actor->business_id)
                ->where('branch_id', $branchId)
                ->where('retail_product_id', $productId)
                ->first();

            $availableBasic = max(0, round((float) ($stock?->on_hand ?? 0) - (float) ($stock?->reserved ?? 0), 4));
            $sellingFactor = max(0.000001, (float) ($product->selling_to_basic_factor ?: 1));
            $requiredBasic = round($sellingUnits * $sellingFactor, 4);

            if ($availableBasic + 0.00005 < $requiredBasic) {
                $availableSellingUnits = max(0, (int) floor(($availableBasic + 0.00005) / $sellingFactor));
                $sellingUom = trim((string) ($product->selling_uom ?: $product->unit ?: 'unit'));
                throw new DomainException($product->name.' has insufficient stock for this Booking. Available: '.$availableSellingUnits.' '.$sellingUom.'.');
            }
        }

        return $result;
    }

    private function assertOwnedAndEditable(User $actor, Booking $booking): void
    {
        if ($booking->business_id !== $actor->business_id) {
            abort(404);
        }
        if (! $booking->isEditableFromBookings()) {
            throw new DomainException('Only active bookings that have not been checked in can be changed from Bookings.');
        }
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }
}
