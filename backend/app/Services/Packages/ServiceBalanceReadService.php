<?php

namespace App\Services\Packages;

use App\Models\PackageEnrollment;
use App\Models\PaymentApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ServiceBalanceReadService
{
    public function list(User $actor, ?string $search = null, ?string $status = null): Collection
    {
        $query = PackageEnrollment::query()
            ->with(['customer', 'serviceCredit', 'booking', 'originSale', 'openingReceivable'])
            ->where('business_id', $actor->business_id)
            ->whereNotIn('status', ['CANCELLED', 'VOIDED']);

        $search = trim((string) $search);
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search) {
                $builder->where('code', 'like', "%{$search}%")
                    ->orWhere('service_name_snapshot', 'like', "%{$search}%")
                    ->orWhereHas('customer', function (Builder $customer) use ($search) {
                        $customer->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('mobile_number', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%");
                    })
                    ->orWhereHas('booking', fn (Builder $booking) => $booking->where('code', 'like', "%{$search}%"));
            });
        }

        $status = strtoupper(trim((string) $status));
        if ($status !== '' && $status !== 'ALL') {
            $query->where('status', $status);
        }

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (PackageEnrollment $enrollment) => $this->summary($enrollment))
            ->values();
    }

    public function detail(User $actor, PackageEnrollment $enrollment): array
    {
        if ((int) $enrollment->business_id !== (int) $actor->business_id) abort(404);

        $enrollment->loadMissing(['customer', 'serviceCredit', 'booking', 'originSale', 'originSaleItem', 'openingReceivable']);
        $summary = $this->summary($enrollment);
        $applications = $this->packageApplications($enrollment);

        return array_merge($summary, [
            'package_price' => (float) $enrollment->package_price_snapshot,
            'package_gross_price' => (float) ($enrollment->package_gross_price_snapshot ?? $enrollment->package_price_snapshot),
            'origin_sale' => $enrollment->originSale ? [
                'id' => $enrollment->originSale->id,
                'code' => $enrollment->originSale->code,
                'status' => $enrollment->originSale->status,
                'sale_date' => $enrollment->originSale->sale_date?->toIso8601String(),
            ] : null,
            'origin_sale_item' => $enrollment->originSaleItem ? [
                'id' => $enrollment->originSaleItem->id,
                'code' => $enrollment->originSaleItem->code,
                'line_total' => (float) $enrollment->originSaleItem->line_total,
                'discount_amount' => (float) ($enrollment->originSaleItem->discount_amount ?? 0),
                'net_line_total' => (float) ($enrollment->originSaleItem->net_line_total ?? $enrollment->originSaleItem->line_total),
                'status' => $enrollment->originSaleItem->status,
            ] : null,
            'payment_history' => $applications->map(function (PaymentApplication $application) use ($enrollment) {
                $payment = $application->payment;
                $booking = $payment?->booking ?? $application->sale?->booking ?? $enrollment->booking;

                return [
                    'application_id' => $application->id,
                    'application_code' => $application->code,
                    'application_type' => $application->target_type,
                    'amount' => (float) $application->amount,
                    'booking' => $booking ? [
                        'id' => $booking->id,
                        'code' => $booking->code,
                    ] : null,
                    'payment' => $payment ? [
                        'id' => $payment->id,
                        'code' => $payment->code,
                        'payment_type' => $payment->payment_type,
                        'method' => $payment->payment_method_snapshot,
                        'reference_no' => $payment->reference_no,
                        'payment_date' => $payment->payment_date?->toIso8601String(),
                    ] : null,
                ];
            })->values(),
        ]);
    }

    public function summary(PackageEnrollment $enrollment): array
    {
        $enrollment->loadMissing(['customer', 'serviceCredit', 'booking', 'openingReceivable']);
        $credit = $enrollment->serviceCredit;
        $purchased = (int) $enrollment->total_sessions;
        $used = $credit
            ? (int) $credit->sessions_used
            : min($purchased, (int) $enrollment->sessions_completed_before_activation);
        $remaining = $credit
            ? (int) $credit->sessions_remaining
            : max(0, $purchased - $used);

        $applicationsPaid = round((float) $this->packageApplications($enrollment)->sum('amount'), 2);
        $price = round((float) $enrollment->package_price_snapshot, 2);
        $openingReceivable = $enrollment->openingReceivable;
        $isOpeningPackage = (bool) $enrollment->opening_balance_id;
        $historicalPaid = $isOpeningPackage
            ? ($openingReceivable
                ? max(0, round($price - (float) $openingReceivable->original_amount, 2))
                : $price)
            : 0.0;
        $totalPaid = round($historicalPaid + $applicationsPaid, 2);
        $balance = $isOpeningPackage
            ? (float) ($openingReceivable?->remaining_balance ?? 0)
            : max(0, round($price - $totalPaid, 2));

        return [
            'id' => $enrollment->id,
            'enrollment_code' => $enrollment->code,
            'customer' => $enrollment->customer ? [
                'id' => $enrollment->customer->id,
                'code' => $enrollment->customer->code,
                'name' => $enrollment->customer->full_name,
                'mobile_number' => $enrollment->customer->mobile_number,
            ] : null,
            'service' => [
                'id' => $enrollment->service_id,
                'name' => $enrollment->service_name_snapshot,
            ],
            'booking' => $enrollment->booking ? [
                'id' => $enrollment->booking->id,
                'code' => $enrollment->booking->code,
                'booking_date' => $enrollment->booking->booking_date?->format('Y-m-d'),
            ] : null,
            'service_credit_code' => $credit?->code,
            'purchased' => $purchased,
            'used' => $used,
            'remaining' => $remaining,
            'total_paid' => $totalPaid,
            'balance' => $balance,
            'status' => $enrollment->status,
            'created_at' => $enrollment->created_at?->toIso8601String(),
            'activated_at' => $enrollment->activated_at?->toIso8601String(),
            'opening_balance' => (bool) $enrollment->opening_balance_id,
        ];
    }

    private function packageApplications(PackageEnrollment $enrollment): Collection
    {
        if (! $enrollment->origin_sale_item_id && ! $enrollment->id) return collect();

        return PaymentApplication::query()
            ->with(['payment.booking', 'sale.booking'])
            ->where('status', 'ACTIVE')
            ->where(function (Builder $query) use ($enrollment) {
                if ($enrollment->origin_sale_item_id) {
                    $query->where('target_sale_item_id', $enrollment->origin_sale_item_id);
                    $query->orWhere('package_enrollment_id', $enrollment->id);
                } else {
                    $query->where('package_enrollment_id', $enrollment->id);
                }
            })
            ->whereHas('payment', fn (Builder $payment) => $payment->where('status', 'RECEIVED'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }
}
