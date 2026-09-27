<?php

namespace App\Services\Sales;

use App\Models\AccountingPaymentMethodMapping;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PaymentApplication;
use App\Models\ReferenceValue;
use App\Models\Sale;
use App\Models\User;
use App\Services\Foundation\BusinessClock;
use App\Services\Foundation\CodeSequenceService;
use App\Services\Security\AuditService;
use DomainException;
use Illuminate\Support\Collection;

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
        if (! is_array($payload) || ! ($payload['collected'] ?? false)) return null;
        if (! $actor->hasPermission('payments', 'CREATE')) throw new DomainException('You do not have permission to receive an Advance Payment.');
        if ($booking->business_id !== $actor->business_id) abort(404);

        $amount = round((float) ($payload['amount'] ?? 0), 2);
        if ($amount <= 0) throw new DomainException('Advance payment amount must be greater than zero.');
        $method = $this->paymentMethod($actor->business_id, (int) ($payload['payment_method_reference_id'] ?? 0));

        $idempotencyKey = 'INITIAL_ADVANCE:'.$booking->id;
        if ($existing = Payment::query()->where('idempotency_key', $idempotencyKey)->first()) return $existing;

        $now = $this->clock->now();
        $payment = Payment::query()->create([
            'business_id' => $actor->business_id,
            'code' => $this->nextCode($actor->business_id),
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
            action: 'RECEIVE_ADVANCE_PAYMENT', module: 'payments', entityType: Payment::class, entityId: $payment->id,
            after: $this->snapshot($payment), actor: $actor,
            metadata: ['booking_id' => $booking->id, 'booking_code' => $booking->code, 'financial_finalization' => false],
        );

        return $payment;
    }

    public function bookingAllocationTotals(Booking $booking): array
    {
        $paymentIds = Payment::query()
            ->where('booking_id', $booking->id)
            ->where('status', 'RECEIVED')
            ->pluck('id');

        if ($paymentIds->isEmpty()) return ['advance' => 0.0, 'interim' => 0.0, 'checkout' => 0.0];

        $advanceReserve = (float) PaymentApplication::query()
            ->whereIn('payment_id', $paymentIds)
            ->where('target_type', 'ADVANCE_RESERVE')
            ->where('target_booking_id', $booking->id)
            ->where('status', 'ACTIVE')
            ->sum('amount');

        $interim = (float) PaymentApplication::query()
            ->whereIn('payment_id', $paymentIds)
            ->where('target_type', 'ON_ACCOUNT_LINE')
            ->where('status', 'ACTIVE')
            ->whereHas('payment', fn ($query) => $query->whereIn('payment_type', ['INTERIM', 'MIXED'])->where('status', 'RECEIVED'))
            ->where(function ($query) {
                $query->whereNull('source_sale_id')
                    ->where(function ($source) {
                        $source->whereNull('target_snapshot->source')
                            ->orWhereNotIn('target_snapshot->source', [
                                'SERVICE_CREDIT_INTERIM_ADVANCE',
                                'SERVICE_CREDIT_INTERIM_CHECKOUT',
                            ]);
                    });
            })
            ->sum('amount');

        $legacyAdvance = (float) Payment::query()
            ->whereIn('id', $paymentIds)
            ->where('payment_type', 'ADVANCE')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')->from('payment_applications')
                    ->whereColumn('payment_applications.payment_id', 'payments.id')
                    ->where('payment_applications.target_type', 'ADVANCE_RESERVE');
            })
            ->sum('amount');

        $checkout = (float) PaymentApplication::query()
            ->whereIn('payment_id', $paymentIds)
            ->where('status', 'ACTIVE')
            ->whereHas('payment', fn ($query) => $query->where('payment_type', 'CHECKOUT')->where('status', 'RECEIVED'))
            ->where(function ($query) {
                $query->where('target_type', 'CHECKOUT_LINE')
                    ->orWhere(function ($serviceCredit) {
                        $serviceCredit->where('target_type', 'ON_ACCOUNT_LINE')
                            ->where('target_snapshot->source', 'SERVICE_CREDIT_INTERIM_CHECKOUT');
                    });
            })
            ->sum('amount');

        $legacyCheckout = (float) Payment::query()
            ->whereIn('id', $paymentIds)
            ->where('payment_type', 'CHECKOUT')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')->from('payment_applications')
                    ->whereColumn('payment_applications.payment_id', 'payments.id');
            })
            ->sum('amount');

        return [
            'advance' => round($advanceReserve + $legacyAdvance, 2),
            'interim' => round($interim, 2),
            'checkout' => round($checkout + $legacyCheckout, 2),
        ];
    }

    public function advanceSourcesForSale(Sale $sale): Collection
    {
        $payments = Payment::query()
            ->where('booking_id', $sale->booking_id)
            ->where('status', 'RECEIVED')
            ->whereIn('payment_type', ['ADVANCE', 'MIXED'])
            ->orderBy('payment_date')->orderBy('id')->get();

        return $payments->map(function (Payment $payment) use ($sale) {
            $reserve = (float) PaymentApplication::query()
                ->where('payment_id', $payment->id)
                ->where('target_type', 'ADVANCE_RESERVE')
                ->where('target_booking_id', $sale->booking_id)
                ->where('status', 'ACTIVE')
                ->sum('amount');

            $base = $reserve > 0.009 ? $reserve : (strtoupper((string) $payment->payment_type) === 'ADVANCE' ? (float) $payment->amount : 0.0);

            $usedQuery = PaymentApplication::query()
                ->where('payment_id', $payment->id)
                ->where('status', 'ACTIVE')
                ->where(function ($query) use ($payment, $sale) {
                    $query->where(function ($currentSale) use ($payment, $sale) {
                        $currentSale->where('sale_id', $sale->id)
                            ->where(function ($targets) use ($payment) {
                                $targets->where('target_type', 'ADVANCE_LINE');
                                if (strtoupper((string) $payment->payment_type) === 'ADVANCE') {
                                    $targets->orWhere('target_type', 'CURRENT_SALE_LINE');
                                }
                            });
                    })->orWhere(function ($serviceCreditAdvance) use ($sale) {
                        $serviceCreditAdvance
                            ->where('source_sale_id', $sale->id)
                            ->where('target_type', 'ON_ACCOUNT_LINE')
                            ->where('target_snapshot->source', 'SERVICE_CREDIT_INTERIM_ADVANCE');
                    });
                });
            $used = (float) $usedQuery->sum('amount');

            return [
                'payment' => $payment,
                'reserved_amount' => round($base, 2),
                'used_amount' => round($used, 2),
                'available' => max(0, round($base - $used, 2)),
            ];
        })->filter(fn ($row) => $row['available'] > 0.009)->values();
    }

    public function advanceAvailableForBooking(Booking $booking): float
    {
        $fake = new Sale(['booking_id' => $booking->id]);
        $fake->id = 0;
        return round((float) $this->advanceSourcesForSale($fake)->sum('available'), 2);
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

    public function nextCode(int $businessId): string
    {
        $now = $this->clock->now();
        return $this->codes->next('PAYMENT:'.$now->format('my'), 'PAY-'.$now->format('my').'-', 6, $businessId);
    }

    private function paymentMethod(int $businessId, int $methodId): ReferenceValue
    {
        if ($methodId <= 0) throw new DomainException('Payment Method is required when a payment is collected.');
        $method = ReferenceValue::query()->whereKey($methodId)->where('active', true)
            ->whereHas('group', fn ($query) => $query->where('business_id', $businessId)->where('code', 'PAYMENT_METHOD')->where('active', true))
            ->first();
        if (! $method) throw new DomainException('Select a valid active Payment Method.');
        $mapped = AccountingPaymentMethodMapping::query()
            ->where('business_id', $businessId)
            ->where('payment_method_reference_id', $method->id)
            ->where('customer_facing', true)
            ->whereHas('cashAccount', fn ($q) => $q->where('active', true)->where('posting_type', 'POSTING')->where('is_cash_account', true))
            ->exists();
        if (! $mapped) throw new DomainException('Select a valid customer payment method configured under Accounting Mapping.');
        return $method;
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }
}
