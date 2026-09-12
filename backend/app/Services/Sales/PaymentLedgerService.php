<?php

namespace App\Services\Sales;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\ReferenceValue;
use App\Models\User;
use App\Services\Foundation\BusinessClock;
use App\Services\Foundation\CodeSequenceService;
use App\Services\Security\AuditService;
use DomainException;

class PaymentLedgerService
{
    public function __construct(
        private readonly CodeSequenceService $codes,
        private readonly BusinessClock $clock,
        private readonly AuditService $audit,
    ) {
    }

    public function recordInitialAdvance(User $actor, Booking $booking, mixed $payload): ?Payment
    {
        if (! is_array($payload) || ! ($payload['collected'] ?? false)) {
            return null;
        }

        if (! $actor->hasPermission('payments', 'CREATE')) {
            throw new DomainException('You do not have permission to receive an Advance Payment.');
        }

        if ($booking->business_id !== $actor->business_id) {
            abort(404);
        }

        $amount = round((float) ($payload['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new DomainException('Advance payment amount must be greater than zero.');
        }

        $methodId = (int) ($payload['payment_method_reference_id'] ?? 0);
        if ($methodId <= 0) {
            throw new DomainException('Payment Method is required when an Advance Payment is collected.');
        }

        $method = ReferenceValue::query()
            ->whereKey($methodId)
            ->where('active', true)
            ->whereHas('group', fn ($query) => $query
                ->where('business_id', $actor->business_id)
                ->where('code', 'PAYMENT_METHOD')
                ->where('active', true))
            ->first();

        if (! $method) {
            throw new DomainException('Select a valid active Payment Method.');
        }

        $idempotencyKey = 'INITIAL_ADVANCE:'.$booking->id;
        $existing = Payment::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        $now = $this->clock->now();
        $payment = Payment::query()->create([
            'business_id' => $actor->business_id,
            'code' => $this->codes->next('PAYMENT:'.$now->format('my'), 'PAY-'.$now->format('my').'-', 6, $actor->business_id),
            'customer_id' => $booking->customer_id,
            'booking_id' => $booking->id,
            'sale_id' => null,
            'visit_id' => null,
            'payment_type' => 'ADVANCE',
            'amount' => $amount,
            'payment_method_reference_id' => $method->id,
            'payment_method_snapshot' => $method->display_value,
            'reference_no' => $this->nullableTrim($payload['reference_no'] ?? null),
            'bank_account' => $this->nullableTrim($payload['bank_account'] ?? null),
            'payment_date' => $now,
            'status' => 'RECEIVED',
            'idempotency_key' => $idempotencyKey,
            'notes' => $this->nullableTrim($payload['notes'] ?? null),
            'created_by' => $actor->id,
        ]);

        $this->audit->record(
            action: 'RECEIVE_ADVANCE_PAYMENT',
            module: 'payments',
            entityType: Payment::class,
            entityId: $payment->id,
            after: $this->snapshot($payment),
            actor: $actor,
            metadata: ['booking_id' => $booking->id, 'booking_code' => $booking->code, 'financial_finalization' => false],
        );

        return $payment;
    }

    public function snapshot(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'code' => $payment->code,
            'booking_id' => $payment->booking_id,
            'sale_id' => $payment->sale_id,
            'visit_id' => $payment->visit_id,
            'customer_id' => $payment->customer_id,
            'payment_type' => $payment->payment_type,
            'amount' => (float) $payment->amount,
            'payment_method' => $payment->payment_method_snapshot,
            'reference_no' => $payment->reference_no,
            'payment_date' => $payment->payment_date?->toIso8601String(),
            'status' => $payment->status,
        ];
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }
}
