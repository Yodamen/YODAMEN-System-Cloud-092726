<?php

namespace App\Services\Operations;

use App\Models\Sale;
use App\Models\Visit;

class CustomerMetricsService
{
    public function forCustomer(
        int $businessId,
        int $customerId,
        ?int $excludeVisitId = null,
        ?int $excludeSaleId = null,
    ): array {
        $visitQuery = Visit::query()
            ->where('business_id', $businessId)
            ->where('customer_id', $customerId)
            ->whereNotIn('status', ['CANCELLED', 'CANCELED', 'NO_SHOW'])
            ->where(function ($query) {
                $query->whereDoesntHave('sale')
                    ->orWhereHas('sale', fn ($saleQuery) => $saleQuery->where('status', '<>', 'REVERSED'));
            });

        if ($excludeVisitId) {
            $visitQuery->where('id', '!=', $excludeVisitId);
        }

        $visits = $visitQuery->get(['id', 'customer_id', 'visit_date', 'status']);

        $saleQuery = Sale::query()
            ->where('business_id', $businessId)
            ->where('customer_id', $customerId)
            ->where('status', '<>', 'REVERSED')
            ->with(['payments' => fn ($query) => $query->where('status', 'RECEIVED')]);

        if ($excludeSaleId) {
            $saleQuery->where('id', '!=', $excludeSaleId);
        }

        $sales = $saleQuery->get();

        $today = now(config('yodamen.business_timezone', 'Asia/Manila'))->startOfDay();
        $lastVisit = $visits
            ->sortByDesc(fn (Visit $visit) => $visit->visit_date?->format('Y-m-d') ?? '')
            ->first()?->visit_date;

        $lifetimeValue = 0.0;
        foreach ($sales as $sale) {
            $received = (float) $sale->payments->sum(fn ($payment) => (float) $payment->amount);
            $saleValue = (float) ($sale->effective_net_amount ?? $sale->net_amount ?? $sale->gross_amount);
            $lifetimeValue += min($saleValue, $received);
        }

        return [
            'last_visit' => $lastVisit?->format('Y-m-d'),
            'days_since_last_visit' => $lastVisit
                ? (int) $lastVisit->startOfDay()->diffInDays($today)
                : null,
            'lifetime_value' => round($lifetimeValue, 2),
            'total_completed_visits' => $visits
                ->filter(fn (Visit $visit) => strtoupper((string) $visit->status) === 'COMPLETED')
                ->count(),
        ];
    }
}
