<?php

namespace App\Services\Packages;

use App\Models\Booking;
use App\Models\BookingServiceLine;
use App\Models\PackageEnrollment;
use App\Models\QueueEntry;
use App\Models\QueueServiceLine;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Service;
use App\Models\ServiceCredit;
use App\Models\ServiceCreditLedger;
use App\Models\ServiceCreditUsage;
use App\Models\ServicePrice;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitServiceLine;
use App\Services\Foundation\BusinessClock;
use App\Services\Foundation\CodeSequenceService;
use App\Services\Security\AuditService;
use DomainException;
use Illuminate\Support\Facades\DB;

class PackageServiceCreditService
{
    public function __construct(
        private readonly CodeSequenceService $codes,
        private readonly BusinessClock $clock,
        private readonly AuditService $audit,
    ) {
    }


    public function availableCreditsForCustomer(User $actor, int $customerId, ?int $excludeBookingId = null, ?string $forDate = null): array
    {
        $date = $forDate ?: $this->clock->today();

        $credits = ServiceCredit::query()
            ->with('packageEnrollment')
            ->where('business_id', $actor->business_id)
            ->where('customer_id', $customerId)
            ->where('status', 'ACTIVE')
            ->where('sessions_remaining', '>', 0)
            ->where(function ($query) use ($date) {
                $query->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', $date);
            })
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->get()
            ->map(function (ServiceCredit $credit) use ($excludeBookingId) {
                $reserved = $this->reservedSessions($credit->id, $excludeBookingId);
                $remaining = max(0, (int) $credit->sessions_remaining);
                $available = max(0, $remaining - $reserved);

                return [
                    'id' => $credit->id,
                    'code' => $credit->code,
                    'package_enrollment_id' => $credit->package_enrollment_id,
                    'package_enrollment_code' => $credit->packageEnrollment?->code,
                    'service_id' => $credit->service_id,
                    'service_name' => $credit->service_name_snapshot,
                    'sessions_purchased' => (int) $credit->sessions_purchased,
                    'sessions_used' => (int) $credit->sessions_used,
                    'sessions_remaining' => $remaining,
                    'reserved_sessions' => $reserved,
                    'available_sessions' => $available,
                    'expiry_date' => $credit->expiry_date?->format('Y-m-d'),
                    'status' => $credit->status,
                ];
            })
            ->filter(fn (array $credit) => $credit['available_sessions'] > 0)
            ->values();

        return [
            'service_credits' => $credits,
            'total_available_sessions' => (int) $credits->sum('available_sessions'),
        ];
    }

    public function resolveCreditForBookingLine(
        User $actor,
        int $customerId,
        int $serviceCreditId,
        ?int $excludeBookingId = null,
        ?string $forDate = null,
    ): array {
        $date = $forDate ?: $this->clock->today();
        $credit = ServiceCredit::query()
            ->with('packageEnrollment')
            ->where('business_id', $actor->business_id)
            ->where('customer_id', $customerId)
            ->whereKey($serviceCreditId)
            ->lockForUpdate()
            ->firstOrFail();

        if (strtoupper((string) $credit->status) !== 'ACTIVE' || (int) $credit->sessions_remaining <= 0) {
            throw new DomainException('The selected Service Credit no longer has an active session balance.');
        }
        if ($credit->expiry_date && $credit->expiry_date->format('Y-m-d') < $date) {
            throw new DomainException('The selected Service Credit expires before this booking date.');
        }

        $reserved = $this->reservedSessions($credit->id, $excludeBookingId);
        $available = max(0, (int) $credit->sessions_remaining - $reserved);
        if ($available <= 0) {
            throw new DomainException('All remaining sessions for the selected Service Credit are already reserved by other active bookings.');
        }

        return ['credit' => $credit, 'available_sessions' => $available];
    }

    public function assertBookingCreditsValidForDate(User $actor, Booking $booking, string $forDate): void
    {
        $booking->loadMissing('serviceLines');
        foreach ($booking->serviceLines as $line) {
            if (! $line->service_credit_id) continue;
            $this->resolveCreditForBookingLine(
                $actor,
                (int) $booking->customer_id,
                (int) $line->service_credit_id,
                (int) $booking->id,
                $forDate,
            );
        }
    }

    public function consumeServiceCredit(User $actor, VisitServiceLine $line): ?ServiceCreditUsage
    {
        if (strtoupper((string) $line->source_type) !== 'SERVICE_CREDIT' || ! $line->service_credit_id) return null;

        $existing = ServiceCreditUsage::query()->where('visit_service_line_id', $line->id)->first();
        if ($existing) return $existing;

        $credit = ServiceCredit::query()->whereKey($line->service_credit_id)->lockForUpdate()->firstOrFail();
        if ((int) $credit->business_id !== (int) $actor->business_id) abort(404);
        if ((int) $credit->customer_id !== (int) $line->visit->customer_id || (int) $credit->service_id !== (int) $line->service_id) {
            throw new DomainException('The selected Service Credit does not match this customer and service.');
        }
        if (strtoupper((string) $credit->status) !== 'ACTIVE' || (int) $credit->sessions_remaining <= 0) {
            throw new DomainException('No Service Credit session remains for this service.');
        }
        if ($credit->expiry_date && $credit->expiry_date->format('Y-m-d') < $line->visit->visit_date->format('Y-m-d')) {
            throw new DomainException('This Service Credit expired before the service date.');
        }

        $now = $this->clock->now();
        $usage = ServiceCreditUsage::query()->create([
            'business_id' => $actor->business_id,
            'code' => $this->codes->next('SERVICE_CREDIT_USAGE:'.$now->format('my'), 'SCU-'.$now->format('my').'-', 8, $actor->business_id),
            'service_credit_id' => $credit->id,
            'package_enrollment_id' => $credit->package_enrollment_id,
            'customer_id' => $credit->customer_id,
            'visit_id' => $line->visit_id,
            'visit_service_line_id' => $line->id,
            'service_id' => $line->service_id,
            'sessions_used' => 1,
            'usage_date' => $now,
            'processed_by' => $actor->id,
        ]);

        $used = min((int) $credit->sessions_purchased, (int) $credit->sessions_used + 1);
        $remaining = max(0, (int) $credit->sessions_remaining - 1);
        $credit->update([
            'sessions_used' => $used,
            'sessions_remaining' => $remaining,
            'status' => $remaining > 0 ? 'ACTIVE' : 'USED',
            'updated_by' => $actor->id,
        ]);

        $enrollment = PackageEnrollment::query()->whereKey($credit->package_enrollment_id)->lockForUpdate()->firstOrFail();
        $enrollment->update([
            'status' => $remaining > 0 ? 'ACTIVE' : 'COMPLETED',
            'completed_at' => $remaining > 0 ? null : ($enrollment->completed_at ?: $now),
            'updated_by' => $actor->id,
        ]);

        $this->appendMovement(
            actor: $actor,
            enrollment: $enrollment,
            serviceCredit: $credit,
            movementType: 'CONSUME',
            sessions: 1,
            movementKey: 'SERVICE_CREDIT_USE:'.$line->id,
            visitServiceLine: $line,
            notes: 'Service Credit session consumed by completed service.',
        );

        $this->audit->record(
            action: 'CONSUME_SERVICE_CREDIT',
            module: 'service-balances',
            entityType: ServiceCredit::class,
            entityId: $credit->id,
            after: [
                'service_credit_id' => $credit->id,
                'code' => $credit->code,
                'sessions_used' => $used,
                'sessions_remaining' => $remaining,
                'visit_id' => $line->visit_id,
                'visit_service_line_id' => $line->id,
            ],
            actor: $actor,
            metadata: ['package_enrollment_id' => $enrollment->id, 'usage_id' => $usage->id],
        );

        return $usage;
    }

    private function reservedSessions(int $serviceCreditId, ?int $excludeBookingId = null): int
    {
        $bookingReservations = BookingServiceLine::query()
            ->where('service_credit_id', $serviceCreditId)
            ->whereHas('booking', function ($query) use ($excludeBookingId) {
                $query->whereNotIn('status', ['CANCELLED', 'COMPLETED']);
                if ($excludeBookingId) $query->whereKeyNot($excludeBookingId);
            })
            ->whereDoesntHave('visitServiceLines', fn ($query) => $query->whereIn('status', ['COMPLETED', 'CANCELLED']))
            ->count();

        $activeVisitClaims = VisitServiceLine::query()
            ->where('service_credit_id', $serviceCreditId)
            ->whereNull('booking_service_line_id')
            ->whereNotIn('status', ['COMPLETED', 'CANCELLED'])
            ->count();

        return $bookingReservations + $activeVisitClaims;
    }

    public function isPackageService(Service $service): bool
    {
        $countCode = strtoupper((string) $service->serviceCount?->code);

        return (int) $service->number_of_sessions > 1
            || in_array($countCode, ['MULTI_SESSION', 'MULTIPLE_SESSION', 'MULTIPLE_SESSIONS'], true);
    }

    public function sourceTypeForNewService(Service $service): string
    {
        return $this->isPackageService($service) ? 'NEW_PACKAGE' : 'NEW_SERVICE';
    }

    public function operationalQuantity(Service $service, int $requested): int
    {
        return $this->isPackageService($service) ? 1 : max(1, $requested);
    }

    public function ensurePendingEnrollment(User $actor, Booking $booking, BookingServiceLine $line): ?PackageEnrollment
    {
        $line->loadMissing('service.serviceCount');
        $service = $line->service;
        if (! $service || strtoupper((string) $line->source_type) !== 'NEW_PACKAGE') {
            return null;
        }

        $existing = null;
        if ($line->package_enrollment_id) {
            $existing = PackageEnrollment::query()->whereKey($line->package_enrollment_id)->first();
        }
        if (! $existing) {
            $existing = PackageEnrollment::query()->where('booking_service_line_id', $line->id)->first();
        }

        $price = $this->servicePrice($service->id, $booking->branch_id, $booking->booking_date?->format('Y-m-d'));
        $sessions = max(1, (int) $service->number_of_sessions);

        if ($existing) {
            if (in_array(strtoupper((string) $existing->status), ['ACTIVE', 'COMPLETED'], true)) {
                return $existing;
            }

            $existing->update([
                'customer_id' => $booking->customer_id,
                'service_id' => $service->id,
                'service_name_snapshot' => $service->name,
                'total_sessions' => $sessions,
                'package_price_snapshot' => $price,
                'package_gross_price_snapshot' => $price,
                'booking_id' => $booking->id,
                'booking_service_line_id' => $line->id,
                'status' => 'PENDING_CHECKOUT',
                'cancelled_at' => null,
                'cancellation_reason' => null,
                'updated_by' => $actor->id,
            ]);
            $enrollment = $existing->fresh();
        } else {
            $now = $this->clock->now();
            $enrollment = PackageEnrollment::query()->create([
                'business_id' => $booking->business_id,
                'code' => $this->codes->next('PACKAGE_ENROLLMENT:'.$now->format('my'), 'PKE-'.$now->format('my').'-', 7, $booking->business_id),
                'customer_id' => $booking->customer_id,
                'service_id' => $service->id,
                'service_name_snapshot' => $service->name,
                'total_sessions' => $sessions,
                'package_price_snapshot' => $price,
                'package_gross_price_snapshot' => $price,
                'booking_id' => $booking->id,
                'booking_service_line_id' => $line->id,
                'status' => 'PENDING_CHECKOUT',
                'sessions_completed_before_activation' => 0,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->audit->record(
                action: 'CREATE_PENDING_PACKAGE',
                module: 'service-balances',
                entityType: PackageEnrollment::class,
                entityId: $enrollment->id,
                after: $this->enrollmentSnapshot($enrollment),
                actor: $actor,
                metadata: ['booking_id' => $booking->id, 'booking_code' => $booking->code],
            );
        }

        $line->update([
            'package_enrollment_id' => $enrollment->id,
            'source_reference_code' => $enrollment->code,
            'quantity' => 1,
            'updated_by' => $actor->id,
        ]);

        return $enrollment;
    }

    public function ensurePendingEnrollmentForVisitLine(User $actor, Visit $visit, VisitServiceLine $line, Service $service): ?PackageEnrollment
    {
        $service->loadMissing('serviceCount');
        if (! $this->isPackageService($service) || strtoupper((string) $line->source_type) !== 'NEW_PACKAGE') {
            return null;
        }

        $price = $this->servicePrice($service->id, $visit->branch_id, $visit->visit_date?->format('Y-m-d'));
        $sessions = max(1, (int) $service->number_of_sessions);
        $now = $this->clock->now();

        $enrollment = PackageEnrollment::query()->create([
            'business_id' => $visit->business_id,
            'code' => $this->codes->next('PACKAGE_ENROLLMENT:'.$now->format('my'), 'PKE-'.$now->format('my').'-', 7, $visit->business_id),
            'customer_id' => $visit->customer_id,
            'service_id' => $service->id,
            'service_name_snapshot' => $service->name,
            'total_sessions' => $sessions,
            'package_price_snapshot' => $price,
            'package_gross_price_snapshot' => $price,
            'booking_id' => $visit->booking_id,
            'visit_id' => $visit->id,
            'visit_service_line_id' => $line->id,
            'status' => 'PENDING_CHECKOUT',
            'sessions_completed_before_activation' => 0,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $line->update([
            'package_enrollment_id' => $enrollment->id,
            'source_reference_code' => $enrollment->code,
            'quantity' => 1,
            'updated_by' => $actor->id,
        ]);

        $this->audit->record(
            action: 'CREATE_PENDING_PACKAGE',
            module: 'service-balances',
            entityType: PackageEnrollment::class,
            entityId: $enrollment->id,
            after: $this->enrollmentSnapshot($enrollment),
            actor: $actor,
            metadata: ['booking_id' => $visit->booking_id, 'visit_id' => $visit->id, 'visit_code' => $visit->code],
        );

        return $enrollment;
    }

    public function cancelPendingForBooking(User $actor, Booking $booking, string $reason): void
    {
        PackageEnrollment::query()
            ->where('business_id', $actor->business_id)
            ->where('booking_id', $booking->id)
            ->where('status', 'PENDING_CHECKOUT')
            ->lockForUpdate()
            ->get()
            ->each(function (PackageEnrollment $enrollment) use ($actor, $reason) {
                $enrollment->update([
                    'status' => 'CANCELLED',
                    'cancelled_at' => $this->clock->now(),
                    'cancellation_reason' => trim($reason),
                    'updated_by' => $actor->id,
                ]);
            });
    }

    public function linkQueueLine(User $actor, QueueEntry $queue, QueueServiceLine $line): void
    {
        if (strtoupper((string) $line->source_type) !== 'NEW_PACKAGE' || ! $line->package_enrollment_id) return;

        $enrollment = PackageEnrollment::query()->whereKey($line->package_enrollment_id)->lockForUpdate()->first();
        if (! $enrollment || (int) $enrollment->business_id !== (int) $actor->business_id) return;

        $enrollment->update([
            'queue_entry_id' => $queue->id,
            'queue_service_line_id' => $line->id,
            'updated_by' => $actor->id,
        ]);
    }

    public function linkVisitLine(User $actor, Visit $visit, VisitServiceLine $line): void
    {
        if (strtoupper((string) $line->source_type) !== 'NEW_PACKAGE' || ! $line->package_enrollment_id) return;

        $enrollment = PackageEnrollment::query()->whereKey($line->package_enrollment_id)->lockForUpdate()->first();
        if (! $enrollment || (int) $enrollment->business_id !== (int) $actor->business_id) return;

        $enrollment->update([
            'visit_id' => $visit->id,
            'visit_service_line_id' => $line->id,
            'updated_by' => $actor->id,
        ]);
    }

    public function markNewPackageSessionCompleted(User $actor, VisitServiceLine $line): void
    {
        if (strtoupper((string) $line->source_type) !== 'NEW_PACKAGE' || ! $line->package_enrollment_id) return;

        $enrollment = PackageEnrollment::query()->whereKey($line->package_enrollment_id)->lockForUpdate()->firstOrFail();
        if (strtoupper((string) $enrollment->status) === 'CANCELLED') {
            throw new DomainException('A cancelled package enrollment cannot record a completed session.');
        }

        $key = 'PENDING_PACKAGE_SERVICE:'.$line->id;
        if (ServiceCreditLedger::query()->where('movement_key', $key)->exists()) return;

        $completed = min(
            (int) $enrollment->total_sessions,
            (int) $enrollment->sessions_completed_before_activation + 1,
        );

        $enrollment->update([
            'visit_id' => $line->visit_id,
            'visit_service_line_id' => $line->id,
            'sessions_completed_before_activation' => $completed,
            'status' => 'PENDING_CHECKOUT',
            'updated_by' => $actor->id,
        ]);

        $this->appendMovement(
            actor: $actor,
            enrollment: $enrollment,
            serviceCredit: null,
            movementType: 'PENDING_PACKAGE_SERVICE',
            sessions: 1,
            movementKey: $key,
            visitServiceLine: $line,
            notes: 'Package session completed before financial activation.',
        );
    }

    public function cancelPendingForVisitLine(User $actor, VisitServiceLine $line, string $reason): void
    {
        if (strtoupper((string) $line->source_type) !== 'NEW_PACKAGE' || ! $line->package_enrollment_id) return;

        $enrollment = PackageEnrollment::query()->whereKey($line->package_enrollment_id)->lockForUpdate()->first();
        if (! $enrollment || strtoupper((string) $enrollment->status) !== 'PENDING_CHECKOUT') return;

        $enrollment->update([
            'status' => 'CANCELLED',
            'cancelled_at' => $this->clock->now(),
            'cancellation_reason' => trim($reason),
            'updated_by' => $actor->id,
        ]);
    }

    public function activateSalePackages(User $actor, Sale $sale): void
    {
        $sale->loadMissing(['items.visitServiceLine', 'booking']);

        foreach ($sale->items->where('item_type', 'PACKAGE') as $item) {
            $this->activateSalePackageItem($actor, $sale, $item);
        }
    }

    private function activateSalePackageItem(User $actor, Sale $sale, SaleItem $item): void
    {
        $enrollmentId = $item->package_enrollment_id ?: $item->visitServiceLine?->package_enrollment_id;
        if (! $enrollmentId) {
            throw new DomainException('Package sale item is missing its Package Enrollment link.');
        }

        $enrollment = PackageEnrollment::query()->whereKey($enrollmentId)->lockForUpdate()->firstOrFail();
        if ((int) $enrollment->business_id !== (int) $actor->business_id) abort(404);
        if (strtoupper((string) $enrollment->status) === 'CANCELLED') {
            throw new DomainException('Cancelled package enrollment cannot be activated.');
        }

        $used = min((int) $enrollment->total_sessions, max(0, (int) $enrollment->sessions_completed_before_activation));
        $remaining = max(0, (int) $enrollment->total_sessions - $used);
        $now = $this->clock->now();

        $credit = ServiceCredit::query()->where('package_enrollment_id', $enrollment->id)->lockForUpdate()->first();
        if (! $credit) {
            $credit = ServiceCredit::query()->create([
                'business_id' => $enrollment->business_id,
                'code' => $this->codes->next('SERVICE_CREDIT:'.$now->format('my'), 'SCR-'.$now->format('my').'-', 7, $enrollment->business_id),
                'package_enrollment_id' => $enrollment->id,
                'customer_id' => $enrollment->customer_id,
                'service_id' => $enrollment->service_id,
                'service_name_snapshot' => $enrollment->service_name_snapshot,
                'sessions_purchased' => $enrollment->total_sessions,
                'sessions_used' => $used,
                'sessions_remaining' => $remaining,
                'status' => $remaining > 0 ? 'ACTIVE' : 'USED',
                'expiry_date' => $enrollment->expiry_date,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
        }

        $enrollment->update([
            'origin_sale_id' => $sale->id,
            'origin_sale_item_id' => $item->id,
            'package_price_snapshot' => round((float) ($item->net_line_total ?? $item->line_total), 2),
            'package_gross_price_snapshot' => round((float) $item->line_total, 2),
            'status' => $remaining > 0 ? 'ACTIVE' : 'COMPLETED',
            'activated_at' => $enrollment->activated_at ?: $now,
            'completed_at' => $remaining > 0 ? null : ($enrollment->completed_at ?: $now),
            'updated_by' => $actor->id,
        ]);

        if (! $item->package_enrollment_id) {
            $item->update([
                'package_enrollment_id' => $enrollment->id,
                'source_reference_code' => $enrollment->code,
                'updated_by' => $actor->id,
            ]);
        }

        $this->appendMovement(
            actor: $actor,
            enrollment: $enrollment,
            serviceCredit: $credit,
            movementType: 'ISSUE',
            sessions: (int) $enrollment->total_sessions,
            movementKey: 'ISSUE:'.$enrollment->id,
            sale: $sale,
            saleItem: $item,
            notes: 'Multi-session package activated at financial finalization.',
        );

        if ($used > 0) {
            $this->appendMovement(
                actor: $actor,
                enrollment: $enrollment,
                serviceCredit: $credit,
                movementType: 'CONSUME',
                sessions: $used,
                movementKey: 'ACTIVATION_CONSUME:'.$enrollment->id,
                visitServiceLine: $item->visitServiceLine,
                sale: $sale,
                saleItem: $item,
                notes: 'Package session completed before activation recognized against issued entitlement.',
            );
        }

        $this->audit->record(
            action: 'ACTIVATE_PACKAGE',
            module: 'service-balances',
            entityType: PackageEnrollment::class,
            entityId: $enrollment->id,
            after: $this->enrollmentSnapshot($enrollment->fresh()),
            actor: $actor,
            metadata: ['sale_id' => $sale->id, 'sale_code' => $sale->code, 'service_credit_id' => $credit->id],
        );
    }

    private function appendMovement(
        User $actor,
        PackageEnrollment $enrollment,
        ?ServiceCredit $serviceCredit,
        string $movementType,
        int $sessions,
        string $movementKey,
        ?VisitServiceLine $visitServiceLine = null,
        ?Sale $sale = null,
        ?SaleItem $saleItem = null,
        ?string $notes = null,
    ): ServiceCreditLedger {
        $existing = ServiceCreditLedger::query()->where('movement_key', $movementKey)->first();
        if ($existing) return $existing;

        $now = $this->clock->now();
        return ServiceCreditLedger::query()->create([
            'business_id' => $enrollment->business_id,
            'code' => $this->codes->next('SERVICE_CREDIT_LEDGER:'.$now->format('my'), 'SCM-'.$now->format('my').'-', 8, $enrollment->business_id),
            'movement_key' => $movementKey,
            'service_credit_id' => $serviceCredit?->id,
            'package_enrollment_id' => $enrollment->id,
            'customer_id' => $enrollment->customer_id,
            'service_id' => $enrollment->service_id,
            'movement_type' => strtoupper($movementType),
            'sessions' => max(0, $sessions),
            'booking_id' => $visitServiceLine?->visit?->booking_id ?: $enrollment->booking_id,
            'queue_entry_id' => $visitServiceLine?->visit?->queue_entry_id ?: $enrollment->queue_entry_id,
            'visit_id' => $visitServiceLine?->visit_id ?: $enrollment->visit_id,
            'visit_service_line_id' => $visitServiceLine?->id ?: $enrollment->visit_service_line_id,
            'sale_id' => $sale?->id,
            'sale_item_id' => $saleItem?->id,
            'notes' => $notes,
            'processed_by' => $actor->id,
        ]);
    }

    private function servicePrice(int $serviceId, ?int $branchId, ?string $date): float
    {
        $date = $date ?: $this->clock->today();
        $price = ServicePrice::query()
            ->where('service_id', $serviceId)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date))
            ->where(fn ($query) => $query->where('branch_id', $branchId)->orWhereNull('branch_id'))
            ->orderByRaw('branch_id is null')
            ->orderByDesc('effective_from')
            ->first();

        return round((float) ($price?->standard_price ?? 0), 2);
    }

    private function enrollmentSnapshot(PackageEnrollment $enrollment): array
    {
        return [
            'id' => $enrollment->id,
            'code' => $enrollment->code,
            'customer_id' => $enrollment->customer_id,
            'service_id' => $enrollment->service_id,
            'total_sessions' => $enrollment->total_sessions,
            'sessions_completed_before_activation' => $enrollment->sessions_completed_before_activation,
            'package_price_snapshot' => (float) $enrollment->package_price_snapshot,
            'package_gross_price_snapshot' => (float) ($enrollment->package_gross_price_snapshot ?? $enrollment->package_price_snapshot),
            'booking_id' => $enrollment->booking_id,
            'origin_sale_id' => $enrollment->origin_sale_id,
            'origin_sale_item_id' => $enrollment->origin_sale_item_id,
            'status' => $enrollment->status,
        ];
    }
}
