<?php

namespace App\Services\Operations;

use App\Models\Booking;
use App\Models\BookingRebooking;
use App\Models\Customer;
use App\Models\Personnel;
use App\Models\Service;
use App\Models\User;
use App\Services\Foundation\BusinessClock;
use App\Services\Foundation\CodeSequenceService;
use App\Services\Security\AuditService;
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

            $source = strtoupper($data['booking_source'] ?? 'RESERVATION');
            if ($source !== 'RESERVATION') {
                throw new DomainException('Walk-In creation becomes available with the Queue phase so a Walk-In can create its Booking and Queue atomically.');
            }

            $branchId = $booking?->branch_id ?? $actor->default_branch_id;
            if (! $branchId) {
                throw new DomainException('A default branch is required before creating bookings.');
            }

            $validatedServices = $this->validateServiceLines($actor, $data['services'] ?? []);
            $validatedRetail = $this->validateRetailLines($data['retail_items'] ?? []);

            if (! $validatedServices && ! $validatedRetail) {
                throw new DomainException('Add at least one service or retail purchase.');
            }

            $before = $booking ? $this->snapshot($booking->load(['customer', 'serviceLines.assignedPersonnel', 'retailLines'])) : null;
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
                'customer_type' => strtoupper($data['customer_type']),
                'booking_date' => $data['booking_date'],
                'booking_time' => $data['booking_time'].':00',
                'booking_source' => $source,
                'notes' => $this->nullableTrim($data['notes'] ?? null),
                'updated_by' => $actor->id,
            ]);
            $booking->save();

            $booking->serviceLines()->delete();
            foreach ($validatedServices as $index => $line) {
                $booking->serviceLines()->create([
                    'code' => $booking->code.'-S'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                    'service_id' => $line['service']->id,
                    'service_name_snapshot' => $line['service']->name,
                    'source_type' => 'NEW_SERVICE',
                    'source_reference_code' => null,
                    'quantity' => $line['quantity'],
                    'assigned_personnel_id' => $line['personnel']?->id,
                    'notes' => $line['notes'],
                    'sort_order' => ($index + 1) * 10,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);
            }

            $booking->retailLines()->delete();
            foreach ($validatedRetail as $index => $line) {
                $booking->retailLines()->create([
                    'code' => $booking->code.'-R'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                    'legacy_retail_item_code' => null,
                    'item_name_snapshot' => $line['item_name'],
                    'quantity' => $line['quantity'],
                    'unit_price_snapshot' => $line['unit_price'],
                    'notes' => $line['notes'],
                    'sort_order' => ($index + 1) * 10,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);
            }

            $booking->refresh()->load(['customer', 'branch', 'serviceLines.service', 'serviceLines.assignedPersonnel', 'retailLines', 'rebookings']);

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
                $this->payments->recordInitialAdvance($actor, $booking, $data['advance_payment'] ?? null);
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

            $before = $this->snapshot($booking->load(['customer', 'serviceLines.assignedPersonnel', 'retailLines']));
            $oldDate = $booking->booking_date->format('Y-m-d');
            $oldTime = substr((string) $booking->booking_time, 0, 5);
            $newDate = $data['booking_date'];
            $newTime = $data['booking_time'];
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
            $booking->refresh()->load(['customer', 'branch', 'serviceLines.service', 'serviceLines.assignedPersonnel', 'retailLines', 'rebookings']);

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
            $booking->load(['customer', 'serviceLines.assignedPersonnel', 'retailLines']);
            $before = $this->snapshot($booking);

            $booking->update([
                'status' => 'CANCELLED',
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancellation_reason' => trim($reason),
                'updated_by' => $actor->id,
            ]);
            $booking->refresh()->load(['customer', 'branch', 'serviceLines.service', 'serviceLines.assignedPersonnel', 'retailLines', 'rebookings']);

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

            if (! $actor->default_branch_id) {
                throw new DomainException('A default branch is required before creating a Walk-In.');
            }

            $validatedServices = $this->validateServiceLines($actor, $data['services'] ?? []);
            $validatedRetail = $this->validateRetailLines($data['retail_items'] ?? []);

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
                'customer_type' => strtoupper($data['customer_type']),
                'booking_date' => $now->toDateString(),
                'booking_time' => $now->format('H:i:s'),
                'booking_source' => 'WALK_IN',
                'status' => 'CHECKED_IN',
                'notes' => $this->nullableTrim($data['notes'] ?? null),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            foreach ($validatedServices as $index => $line) {
                $booking->serviceLines()->create([
                    'code' => $booking->code.'-S'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                    'service_id' => $line['service']->id,
                    'service_name_snapshot' => $line['service']->name,
                    'source_type' => 'NEW_SERVICE',
                    'source_reference_code' => null,
                    'quantity' => $line['quantity'],
                    'assigned_personnel_id' => $line['personnel']?->id,
                    'notes' => $line['notes'],
                    'sort_order' => ($index + 1) * 10,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);
            }

            foreach ($validatedRetail as $index => $line) {
                $booking->retailLines()->create([
                    'code' => $booking->code.'-R'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                    'legacy_retail_item_code' => null,
                    'item_name_snapshot' => $line['item_name'],
                    'quantity' => $line['quantity'],
                    'unit_price_snapshot' => $line['unit_price'],
                    'notes' => $line['notes'],
                    'sort_order' => ($index + 1) * 10,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);
            }

            $booking->refresh()->load(['customer', 'branch', 'serviceLines.service', 'serviceLines.assignedPersonnel', 'retailLines', 'rebookings']);

            $this->audit->record(
                action: 'CREATE_WALK_IN',
                module: 'bookings',
                entityType: Booking::class,
                entityId: $booking->id,
                after: $this->snapshot($booking),
                actor: $actor,
                metadata: ['source' => 'WALK_IN'],
            );

            $this->payments->recordInitialAdvance($actor, $booking, $data['advance_payment'] ?? null);

            return $booking;
        });
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
                'quantity' => $line->quantity,
                'assigned_personnel_id' => $line->assigned_personnel_id,
            ])->values()->all(),
            'retail_items' => $booking->retailLines->map(fn ($line) => [
                'code' => $line->code,
                'item_name' => $line->item_name_snapshot,
                'quantity' => $line->quantity,
                'unit_price' => (float) $line->unit_price_snapshot,
            ])->values()->all(),
        ];
    }

    private function validateServiceLines(User $actor, array $lines): array
    {
        $result = [];
        foreach ($lines as $line) {
            $service = Service::query()
                ->with('statusReference')
                ->where('business_id', $actor->business_id)
                ->whereKey($line['service_id'])
                ->firstOrFail();

            if (strtoupper((string) $service->statusReference?->code) !== 'ACTIVE') {
                throw new DomainException('Inactive services cannot be added to a booking.');
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
                'quantity' => max(1, (int) ($line['quantity'] ?? 1)),
                'personnel' => $personnel,
                'notes' => $this->nullableTrim($line['notes'] ?? null),
            ];
        }
        return $result;
    }

    private function validateRetailLines(array $lines): array
    {
        $result = [];
        foreach ($lines as $line) {
            $name = trim((string) ($line['item_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $result[] = [
                'item_name' => $name,
                'quantity' => max(1, (int) ($line['quantity'] ?? 1)),
                'unit_price' => round(max(0, (float) ($line['unit_price'] ?? 0)), 2),
                'notes' => $this->nullableTrim($line['notes'] ?? null),
            ];
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
