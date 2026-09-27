<?php

namespace App\Services\Sales;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\ReferenceValue;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Service;
use App\Models\TransactionRefund;
use App\Models\User;
use App\Models\Visit;
use App\Services\Foundation\BusinessClock;
use App\Services\Customers\CustomerClassificationService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;

class SalesReportingService
{
    public function __construct(
        private readonly BusinessClock $clock,
        private readonly CustomerClassificationService $classification,
    ) {
    }

    public function dashboard(User $actor, array $filters = []): array
    {
        $today = CarbonImmutable::parse($this->clock->today(), $this->clock->timezone());
        $from = $this->date($filters['from'] ?? null, $today->startOfMonth()->toDateString());
        $to = $this->date($filters['to'] ?? null, $today->toDateString());
        if ($from > $to) throw new DomainException('From date cannot be after To date.');

        $branchId = ! empty($filters['branch_id']) ? $this->branchId($actor, (int) $filters['branch_id']) : ($actor->default_branch_id ?: null);
        $topN = max(3, min(50, (int) ($filters['top_n'] ?? 10)));
        $compareEnabled = filter_var($filters['compare_enabled'] ?? false, FILTER_VALIDATE_BOOL);
        [$compareFrom, $compareTo, $comparisonMode] = $this->comparisonRange($from, $to, $compareEnabled, $filters['compare_from'] ?? null, $filters['compare_to'] ?? null);

        $report = $this->build($actor, $from, $to, $branchId, $topN);
        $comparison = $this->build($actor, $compareFrom, $compareTo, $branchId, $topN);
        $report['comparison'] = [
            'summary' => $comparison['summary'],
            'range' => ['from' => $compareFrom, 'to' => $compareTo],
            'mode' => $comparisonMode,
            'label' => $this->comparisonLabel($compareFrom, $compareTo),
            'changes' => $this->summaryChanges($report['summary'], $comparison['summary']),
        ];
        $report['branches'] = $this->branches($actor);
        $report['trend_years'] = $this->availableYears($actor, $branchId);
        $report['filters'] = [
            'from' => $from,
            'to' => $to,
            'branch_id' => $branchId,
            'top_n' => $topN,
            'compare_enabled' => $compareEnabled,
            'compare_from' => $compareFrom,
            'compare_to' => $compareTo,
        ];

        return $report;
    }


    public function trend(User $actor, array $filters = []): array
    {
        $year = (int) ($filters['year'] ?? CarbonImmutable::parse($this->clock->today(), $this->clock->timezone())->year);
        if ($year < 2000 || $year > 2100) {
            throw new DomainException('Select a valid trend year.');
        }

        $type = strtolower(trim((string) ($filters['type'] ?? 'sales_type')));
        $allowed = [
            'sales_type' => 'Sales Type',
            'sales_mix' => 'Sales Mix',
            'customer_mix' => 'Customer Mix',
            'customer_class' => 'Customer Class',
            'service_category' => 'Service Category',
            'booking_type' => 'Booking Type',
            'payment_method_mix' => 'Payment Method Mix',
            'average_booking_value' => 'Average Booking Value',
            'client_visits' => 'Client Visits',
            'average_lifetime_value' => 'Average Lifetime Value',
            'unique_customers' => 'Unique Customers',
        ];
        if (! isset($allowed[$type])) {
            throw new DomainException('Select a valid Sales Dashboard trend.');
        }

        $branchId = ! empty($filters['branch_id'])
            ? $this->branchId($actor, (int) $filters['branch_id'])
            : ($actor->default_branch_id ?: null);

        $summaryMetrics = [
            'average_booking_value' => ['key' => 'average_booking_value', 'label' => 'Average Booking Value', 'measure' => 'amount'],
            'client_visits' => ['key' => 'client_visits', 'label' => 'Client Visits', 'measure' => 'count'],
            'average_lifetime_value' => ['key' => 'average_lifetime_value', 'label' => 'Average Lifetime Value', 'measure' => 'amount'],
            'unique_customers' => ['key' => 'unique_customers', 'label' => 'Unique Customers', 'measure' => 'count'],
        ];

        $months = collect(range(1, 12))->map(function (int $month) use ($actor, $year, $branchId, $type, $summaryMetrics) {
            $monthDate = CarbonImmutable::create($year, $month, 1, 0, 0, 0, $this->clock->timezone());
            $report = $this->build(
                $actor,
                $monthDate->startOfMonth()->toDateString(),
                $monthDate->endOfMonth()->toDateString(),
                $branchId,
                3,
            );
            if (isset($summaryMetrics[$type])) {
                $metric = $summaryMetrics[$type];
                $value = round((float) ($report['summary'][$metric['key']] ?? 0), 2);
                return [
                    'month' => $monthDate->format('M'),
                    'month_number' => $month,
                    'values' => [$metric['label'] => $value],
                    'total' => $value,
                ];
            }

            $rows = collect($report[$type] ?? []);
            $values = $rows->mapWithKeys(
                fn (array $row) => [(string) ($row['label'] ?? $row['payment_method'] ?? 'Unspecified') => round((float) ($row['amount'] ?? 0), 2)]
            )->all();

            return [
                'month' => $monthDate->format('M'),
                'month_number' => $month,
                'values' => $values,
                'total' => round((float) $rows->sum(fn (array $row) => (float) ($row['amount'] ?? 0)), 2),
            ];
        });

        if (isset($summaryMetrics[$type])) {
            $series = [$summaryMetrics[$type]['label']];
        } elseif ($type === 'customer_mix') {
            $series = collect($this->classification->typeOptions((int) $actor->business_id))
                ->pluck('name')
                ->filter()
                ->values()
                ->all();
            if ($series === []) $series = ['Unclassified'];
        } elseif ($type === 'customer_class') {
            $series = collect($this->classification->classOptions((int) $actor->business_id))
                ->pluck('name')
                ->filter()
                ->values()
                ->all();
            if ($series === []) $series = ['Unclassified'];
        } else {
            $series = $this->trendSeries($type, $months);
        }

        return [
            'type' => $type,
            'title' => $allowed[$type],
            'year' => $year,
            'years' => $this->availableYears($actor, $branchId),
            'branch_id' => $branchId,
            'measure' => $summaryMetrics[$type]['measure'] ?? 'amount',
            'series' => $series,
            'months' => $months->map(function (array $month) use ($series) {
                $values = collect($series)->mapWithKeys(
                    fn (string $label) => [$label => round((float) ($month['values'][$label] ?? 0), 2)]
                )->all();

                return [
                    'month' => $month['month'],
                    'month_number' => $month['month_number'],
                    'values' => $values,
                    'total' => round((float) array_sum($values), 2),
                ];
            })->values(),
        ];
    }

    public function daily(User $actor, int $branchId, string $date): array
    {
        $branchId = $this->branchId($actor, $branchId);
        $day = $this->date($date, $this->clock->today());
        $report = $this->build($actor, $day, $day, $branchId, 20);
        $branch = Branch::query()->where('business_id', $actor->business_id)->findOrFail($branchId);

        return [
            'branch' => ['id' => $branch->id, 'code' => $branch->code, 'name' => $branch->name],
            'business_date' => $day,
            ...$report,
        ];
    }

    private function build(User $actor, string $from, string $to, ?int $branchId, int $topN): array
    {
        $sales = Sale::query()
            ->where('business_id', $actor->business_id)
            ->whereNotNull('financial_finalized_at')
            ->where('status', '<>', 'REVERSED')
            ->whereDate('sale_date', '>=', $from)
            ->whereDate('sale_date', '<=', $to)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->get(['id', 'branch_id', 'booking_id', 'customer_id', 'sale_channel', 'gross_amount', 'discount_amount', 'net_amount', 'effective_net_amount', 'outstanding_balance']);

        $saleIds = $sales->pluck('id');
        $gross = round((float) $sales->sum('gross_amount'), 2);
        $discounts = round((float) $sales->sum('discount_amount'), 2);
        $net = round((float) $sales->sum(fn (Sale $sale) => (float) ($sale->effective_net_amount ?? $sale->net_amount)), 2);
        $outstanding = round((float) $sales->sum('outstanding_balance'), 2);
        $transactions = $sales->count();

        $paymentsQuery = Payment::query()
            ->where('business_id', $actor->business_id)
            ->where('status', 'RECEIVED')
            ->where('payment_type', '!=', 'TIP')
            // Keep the immutable payment record for audit, but remove receipts
            // linked to a reversed transaction from effective sales reporting.
            ->whereDoesntHave('sale', fn ($saleQuery) => $saleQuery->where('status', 'REVERSED'))
            ->whereDoesntHave('booking.sale', fn ($saleQuery) => $saleQuery->where('status', 'REVERSED'))
            ->whereDate('payment_date', '>=', $from)
            ->whereDate('payment_date', '<=', $to);

        if ($branchId) {
            $paymentsQuery->where(function ($q) use ($branchId) {
                $q->whereHas('sale', fn ($sq) => $sq->where('branch_id', $branchId))
                    ->orWhereHas('booking', fn ($bq) => $bq->where('branch_id', $branchId));
            });
        }

        $payments = $paymentsQuery
            ->with(['sale', 'booking.sale'])
            ->get(['id', 'sale_id', 'booking_id', 'amount', 'payment_method_snapshot', 'payment_date']);
        $effectivePaymentAmounts = $this->effectivePaymentAmounts($actor, $payments);
        $paymentsReceived = round((float) $payments->sum(
            fn (Payment $payment) => (float) ($effectivePaymentAmounts[$payment->id] ?? $payment->amount)
        ), 2);

        $refundsQuery = TransactionRefund::query()
            ->where('business_id', $actor->business_id)
            ->where('status', 'POSTED')
            ->whereDate('processed_at', '>=', $from)
            ->whereDate('processed_at', '<=', $to)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));
        $refunds = $refundsQuery->get(['adjustment_amount', 'cash_refund_amount']);
        $refundAdjustmentsPosted = round((float) $refunds->sum('adjustment_amount'), 2);
        $cashRefunds = round((float) $refunds->sum('cash_refund_amount'), 2);

        $paymentMethods = $payments->groupBy(fn ($payment) => trim((string) ($payment->payment_method_snapshot ?: 'Unspecified')))
            ->map(function ($rows, $method) use ($effectivePaymentAmounts) {
                $effectiveRows = $rows->filter(
                    fn (Payment $payment) => (float) ($effectivePaymentAmounts[$payment->id] ?? $payment->amount) > 0.009
                );

                return [
                    'payment_method' => $method,
                    'payments' => $effectiveRows->count(),
                    'amount' => round((float) $rows->sum(
                        fn (Payment $payment) => (float) ($effectivePaymentAmounts[$payment->id] ?? $payment->amount)
                    ), 2),
                ];
            })->filter(fn ($row) => (float) $row['amount'] > 0.009)->sortByDesc('amount')->values();

        $items = $saleIds->isEmpty()
            ? collect()
            : SaleItem::query()->whereIn('sale_id', $saleIds)
                ->whereNotIn('correction_status', ['REMOVED', 'REVERSED'])
                ->get(['sale_id', 'service_id', 'item_type', 'item_name_snapshot', 'quantity', 'line_total', 'net_line_total', 'effective_net_line_total']);

        $itemAmountTotal = round((float) $items->sum(fn ($item) => $this->itemAmount($item)), 2);

        $salesType = collect([
            'Services' => $items->filter(fn ($item) => strtoupper((string) $item->item_type) !== 'PRODUCT'),
            'Retail' => $items->filter(fn ($item) => strtoupper((string) $item->item_type) === 'PRODUCT'),
        ])->map(fn ($rows, $label) => $this->mixRow($label, $rows, $itemAmountTotal))->values();

        $salesMix = collect([
            'Services' => $items->filter(fn ($item) => in_array(strtoupper((string) $item->item_type), ['SERVICE', 'SERVICE_CREDIT_USE'], true)),
            'Packages' => $items->filter(fn ($item) => strtoupper((string) $item->item_type) === 'PACKAGE'),
            'Products' => $items->filter(fn ($item) => strtoupper((string) $item->item_type) === 'PRODUCT'),
        ])->map(fn ($rows, $label) => $this->mixRow($label, $rows, $itemAmountTotal))->values();

        $serviceCategory = $this->serviceCategoryMix($actor, $items);
        $bookingType = $this->bookingTypeMix($actor, $sales);
        $paymentMethodMix = $paymentMethods->map(function (array $row) use ($paymentsReceived) {
            return [
                'label' => $row['payment_method'],
                'count' => (int) $row['payments'],
                'amount' => round((float) $row['amount'], 2),
                'percent_amount' => $this->percent((float) $row['amount'], $paymentsReceived),
            ];
        })->values();

        $topServices = $this->topItems(
            $items->filter(fn ($item) => in_array(strtoupper((string) $item->item_type), ['SERVICE', 'SERVICE_CREDIT_USE'], true)),
            $itemAmountTotal,
            $topN,
        );
        $topRetailItems = $this->topItems(
            $items->filter(fn ($item) => strtoupper((string) $item->item_type) === 'PRODUCT'),
            $itemAmountTotal,
            $topN,
        );

        $customerIds = $sales->pluck('customer_id')->filter()->unique()->map(fn ($id) => (int) $id)->values();
        $customers = $customerIds->isEmpty()
            ? collect()
            : Customer::query()->where('business_id', $actor->business_id)->whereIn('id', $customerIds)->get(['id', 'code', 'first_name', 'last_name'])->keyBy('id');

        $visitCounts = $this->customerVisitCounts($actor, $customerIds->all(), $to);
        $lifetimeValues = $this->customerLifetimeValuesThrough($actor, $customerIds, $to);
        $identifiedSalesTotal = round((float) $sales->whereNotNull('customer_id')->sum(fn (Sale $sale) => (float) ($sale->effective_net_amount ?? $sale->net_amount)), 2);

        $topClients = $sales->whereNotNull('customer_id')
            ->groupBy('customer_id')
            ->map(function ($rows, $customerId) use ($customers, $visitCounts, $lifetimeValues, $net, $actor) {
                $customer = $customers->get((int) $customerId);
                $amount = round((float) $rows->sum(fn (Sale $sale) => (float) ($sale->effective_net_amount ?? $sale->net_amount)), 2);
                $type = $this->classification->typeFor((int) $actor->business_id, (int) ($visitCounts[(int) $customerId] ?? 0));
                $class = $this->classification->classFor((int) $actor->business_id, (float) ($lifetimeValues[(int) $customerId] ?? 0));

                return [
                    'customer_id' => (int) $customerId,
                    'name' => $customer?->full_name ?: 'Customer',
                    'segment' => $type['name'],
                    'customer_type' => $type['name'],
                    'customer_type_color' => $type['color'],
                    'customer_class' => $class['name'] ?? 'Unclassified',
                    'count' => $rows->count(),
                    'amount' => $amount,
                    'percent_amount' => $this->percent($amount, $net),
                ];
            })->sortByDesc('amount')->take($topN)->values();

        $customerMixBuckets = collect($this->classification->typeOptions((int) $actor->business_id))
            ->mapWithKeys(fn (array $row) => [(string) $row['name'] => []])
            ->all();
        if ($customerMixBuckets === []) $customerMixBuckets = ['Unclassified' => []];

        foreach ($sales->whereNotNull('customer_id')->groupBy('customer_id') as $customerId => $rows) {
            $segment = $this->customerSegment((int) $actor->business_id, (int) ($visitCounts[(int) $customerId] ?? 0));
            if (! array_key_exists($segment, $customerMixBuckets)) $customerMixBuckets[$segment] = [];
            $customerMixBuckets[$segment][] = [
                'customer_id' => (int) $customerId,
                'amount' => round((float) $rows->sum(fn (Sale $sale) => (float) ($sale->effective_net_amount ?? $sale->net_amount)), 2),
            ];
        }
        $customerMix = collect($customerMixBuckets)->map(function (array $rows, string $label) use ($identifiedSalesTotal) {
            $amount = round((float) collect($rows)->sum('amount'), 2);
            return [
                'label' => $label,
                'count' => count($rows),
                'amount' => $amount,
                'percent_amount' => $this->percent($amount, $identifiedSalesTotal),
            ];
        })->values();

        $customerClassBuckets = collect($this->classification->classOptions((int) $actor->business_id))
            ->mapWithKeys(fn (array $row) => [(string) $row['name'] => []])
            ->all();
        if ($customerClassBuckets === []) $customerClassBuckets = ['Unclassified' => []];

        foreach ($sales->whereNotNull('customer_id')->groupBy('customer_id') as $customerId => $rows) {
            $class = $this->classification->classFor(
                (int) $actor->business_id,
                (float) ($lifetimeValues[(int) $customerId] ?? 0)
            );
            $label = $class['name'] ?? 'Unclassified';
            if (! array_key_exists($label, $customerClassBuckets)) $customerClassBuckets[$label] = [];
            $customerClassBuckets[$label][] = [
                'customer_id' => (int) $customerId,
                'amount' => round((float) $rows->sum(fn (Sale $sale) => (float) ($sale->effective_net_amount ?? $sale->net_amount)), 2),
            ];
        }
        $customerClassMix = collect($customerClassBuckets)->map(function (array $rows, string $label) use ($identifiedSalesTotal) {
            $amount = round((float) collect($rows)->sum('amount'), 2);
            return [
                'label' => $label,
                'count' => count($rows),
                'amount' => $amount,
                'percent_amount' => $this->percent($amount, $identifiedSalesTotal),
            ];
        })->values();

        $visitsQuery = Visit::query()
            ->where('business_id', $actor->business_id)
            ->where('status', 'COMPLETED')
            ->whereHas('sale', fn ($q) => $q->where('status', '<>', 'REVERSED'))
            ->whereDate('visit_date', '>=', $from)
            ->whereDate('visit_date', '<=', $to)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));
        $clientVisits = (clone $visitsQuery)->count();
        $uniqueCustomers = (clone $visitsQuery)->distinct()->count('customer_id');

        $bookingSales = $sales->filter(fn (Sale $sale) => $sale->booking_id !== null);
        $bookingCount = $bookingSales->pluck('booking_id')->filter()->unique()->count();
        $bookingNet = round((float) $bookingSales->sum(fn (Sale $sale) => (float) ($sale->effective_net_amount ?? $sale->net_amount)), 2);
        $averageBookingValue = $bookingCount > 0 ? round($bookingNet / $bookingCount, 2) : 0.0;

        $averageLifetimeValue = $lifetimeValues->isNotEmpty()
            ? round((float) $lifetimeValues->avg(), 2)
            : 0.0;

        return [
            'summary' => [
                'transactions' => $transactions,
                'gross_sales' => $gross,
                'discounts' => $discounts,
                'net_sales' => $net,
                'payments_received' => $paymentsReceived,
                'cash_refunds' => $cashRefunds,
                'net_cash_collections' => round($paymentsReceived - $cashRefunds, 2),
                'refund_adjustments_posted' => $refundAdjustmentsPosted,
                'outstanding_balance' => $outstanding,
                'average_transaction' => $transactions ? round($net / $transactions, 2) : 0.0,
                'average_booking_value' => $averageBookingValue,
                'average_lifetime_value' => $averageLifetimeValue,
                'client_visits' => $clientVisits,
                'unique_customers' => $uniqueCustomers,
            ],
            'payment_methods' => $paymentMethods,
            'payment_method_mix' => $paymentMethodMix,
            'sales_type' => $salesType,
            'sales_mix' => $salesMix,
            'customer_mix' => $customerMix,
            'customer_class' => $customerClassMix,
            'service_category' => $serviceCategory,
            'booking_type' => $bookingType,
            'top_services' => $topServices,
            'top_retail_items' => $topRetailItems,
            'top_clients' => $topClients,
        ];
    }

    /**
     * Reporting keeps immutable RECEIVED Payment rows for audit, while a paid
     * transaction that was later reduced by an approved Correction must not keep
     * overstating effective Collections. The excess remains Refund Due.
     *
     * Refund Due is allocated against the latest received payment(s) for the Sale.
     * This keeps prior/advance receipts intact until they are the only remaining
     * source of the excess and makes Dashboard and live Z-Reading agree with the
     * transaction's current effective financial position.
     *
     * @return Collection<int, float> payment_id => effective reporting amount
     */
    private function effectivePaymentAmounts(User $actor, Collection $periodPayments): Collection
    {
        $effective = $periodPayments->mapWithKeys(
            fn (Payment $payment) => [(int) $payment->id => round((float) $payment->amount, 2)]
        );

        $saleIds = $periodPayments->pluck('sale_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $bookingIds = $periodPayments->pluck('booking_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($saleIds->isEmpty() && $bookingIds->isEmpty()) {
            return $effective;
        }

        // Resolve Refund Due directly from Sales instead of depending on an
        // already-loaded Payment relation. Payments are immutable audit rows;
        // Sales carries the current effective correction/refund position.
        $saleMap = Sale::query()
            ->where('business_id', $actor->business_id)
            ->where('status', '<>', 'REVERSED')
            ->where('refund_due_amount', '>', 0)
            ->where(function ($query) use ($saleIds, $bookingIds) {
                if ($saleIds->isNotEmpty()) {
                    $query->whereIn('id', $saleIds->all());
                }
                if ($bookingIds->isNotEmpty()) {
                    $method = $saleIds->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                    $query->{$method}('booking_id', $bookingIds->all());
                }
            })
            ->get(['id', 'booking_id', 'refund_due_amount'])
            ->keyBy('id');

        foreach ($saleMap as $sale) {
            $refundDue = max(0, round((float) ($sale->refund_due_amount ?? 0), 2));
            if ($refundDue <= 0.009) continue;

            $allPayments = Payment::query()
                ->where('business_id', $actor->business_id)
                ->where('status', 'RECEIVED')
                ->where('payment_type', '!=', 'TIP')
                ->where(function ($query) use ($sale) {
                    $query->where('sale_id', $sale->id);
                    if ($sale->booking_id) {
                        $query->orWhere('booking_id', $sale->booking_id);
                    }
                })
                ->orderByDesc('payment_date')
                ->orderByDesc('id')
                ->get(['id', 'amount']);

            $remaining = min($refundDue, round((float) $allPayments->sum('amount'), 2));
            foreach ($allPayments as $payment) {
                if ($remaining <= 0.009) break;
                $amount = round((float) $payment->amount, 2);
                $reduction = min($amount, $remaining);
                if ($effective->has((int) $payment->id)) {
                    $effective->put((int) $payment->id, max(0, round($amount - $reduction, 2)));
                }
                $remaining = round($remaining - $reduction, 2);
            }
        }

        return $effective;
    }

    private function serviceCategoryMix(User $actor, Collection $items): Collection
    {
        $activeCategories = ReferenceValue::query()
            ->where('active', true)
            ->whereHas('group', fn ($query) => $query
                ->where('business_id', $actor->business_id)
                ->where('code', 'SERVICE_CATEGORY')
                ->where('active', true))
            ->orderBy('sort_order')
            ->orderBy('display_value')
            ->get(['id', 'display_value']);

        if ($activeCategories->isEmpty()) return collect();

        $serviceIds = $items->pluck('service_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $serviceCategories = $serviceIds->isEmpty()
            ? collect()
            : Service::query()
                ->where('business_id', $actor->business_id)
                ->whereIn('id', $serviceIds->all())
                ->pluck('service_category_reference_id', 'id');

        $eligibleItems = $items->filter(function (SaleItem $item) use ($serviceCategories, $activeCategories) {
            if (! $item->service_id) return false;
            $categoryId = (int) ($serviceCategories[(int) $item->service_id] ?? 0);
            return $categoryId > 0 && $activeCategories->contains('id', $categoryId);
        });
        $denominator = round((float) $eligibleItems->sum(fn (SaleItem $item) => $this->itemAmount($item)), 2);

        return $activeCategories->map(function (ReferenceValue $category) use ($eligibleItems, $serviceCategories, $denominator) {
            $rows = $eligibleItems->filter(
                fn (SaleItem $item) => (int) ($serviceCategories[(int) $item->service_id] ?? 0) === (int) $category->id
            );
            return $this->mixRow((string) $category->display_value, $rows, $denominator);
        })->values();
    }

    private function bookingTypeMix(User $actor, Collection $sales): Collection
    {
        $bookingIds = $sales->pluck('booking_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $sources = $bookingIds->isEmpty()
            ? collect()
            : Booking::query()
                ->where('business_id', $actor->business_id)
                ->whereIn('id', $bookingIds->all())
                ->pluck('booking_source', 'id');

        $bookingSales = $sales->filter(function (Sale $sale) use ($sources) {
            $source = strtoupper((string) ($sources[(int) $sale->booking_id] ?? ''));
            return in_array($source, ['WALK_IN', 'RESERVATION'], true);
        });
        $denominator = round((float) $bookingSales->sum(fn (Sale $sale) => (float) ($sale->effective_net_amount ?? $sale->net_amount)), 2);

        return collect([
            'Walk-In' => 'WALK_IN',
            'Reservation' => 'RESERVATION',
        ])->map(function (string $source, string $label) use ($bookingSales, $sources, $denominator) {
            $rows = $bookingSales->filter(
                fn (Sale $sale) => strtoupper((string) ($sources[(int) $sale->booking_id] ?? '')) === $source
            );
            $amount = round((float) $rows->sum(fn (Sale $sale) => (float) ($sale->effective_net_amount ?? $sale->net_amount)), 2);
            return [
                'label' => $label,
                'count' => $rows->count(),
                'amount' => $amount,
                'percent_amount' => $this->percent($amount, $denominator),
            ];
        })->values();
    }

    private function trendSeries(string $type, Collection $months): array
    {
        $fixed = match ($type) {
            'sales_type' => ['Services', 'Retail'],
            'sales_mix' => ['Services', 'Packages', 'Products'],
            'booking_type' => ['Walk-In', 'Reservation'],
            default => null,
        };
        if ($fixed !== null) return $fixed;

        $totals = [];
        foreach ($months as $month) {
            foreach (($month['values'] ?? []) as $label => $amount) {
                $totals[$label] = ($totals[$label] ?? 0) + (float) $amount;
            }
        }

        if ($type === 'service_category') {
            return array_keys($totals);
        }

        arsort($totals, SORT_NUMERIC);
        return array_keys($totals);
    }

    private function availableYears(User $actor, ?int $branchId): array
    {
        $query = Sale::query()
            ->where('business_id', $actor->business_id)
            ->whereNotNull('financial_finalized_at')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));

        $min = (clone $query)->min('sale_date');
        $max = (clone $query)->max('sale_date');
        $currentYear = (int) CarbonImmutable::parse($this->clock->today(), $this->clock->timezone())->year;

        if (! $min || ! $max) return [$currentYear];

        $minYear = (int) CarbonImmutable::parse($min, $this->clock->timezone())->year;
        $maxYear = max($currentYear, (int) CarbonImmutable::parse($max, $this->clock->timezone())->year);
        return array_values(array_reverse(range($minYear, $maxYear)));
    }

    private function topItems($items, float $denominator, int $topN)
    {
        return $items->groupBy(fn ($item) => trim((string) $item->item_name_snapshot))
            ->map(function ($rows, $name) use ($denominator) {
                $amount = round((float) $rows->sum(fn ($row) => $this->itemAmount($row)), 2);
                return [
                    'name' => $name ?: 'Unnamed Item',
                    'count' => (int) $rows->sum('quantity'),
                    'amount' => $amount,
                    'percent_amount' => $this->percent($amount, $denominator),
                ];
            })->sortByDesc('amount')->take($topN)->values();
    }

    private function mixRow(string $label, $rows, float $denominator): array
    {
        $amount = round((float) $rows->sum(fn ($row) => $this->itemAmount($row)), 2);
        return [
            'label' => $label,
            'count' => (int) $rows->sum('quantity'),
            'amount' => $amount,
            'percent_amount' => $this->percent($amount, $denominator),
        ];
    }

    private function itemAmount(SaleItem $item): float
    {
        return (float) ($item->effective_net_line_total ?? $item->net_line_total ?? $item->line_total ?? 0);
    }

    private function percent(float $amount, float $total): float
    {
        return $total > 0.00001 ? round(($amount / $total) * 100, 1) : 0.0;
    }

    private function customerVisitCounts(User $actor, array $customerIds, string $throughDate): array
    {
        if ($customerIds === []) return [];

        return Visit::query()
            ->where('business_id', $actor->business_id)
            ->whereIn('customer_id', $customerIds)
            ->where('status', 'COMPLETED')
            ->whereHas('sale', fn ($q) => $q->where('status', '<>', 'REVERSED'))
            ->whereDate('visit_date', '<=', $throughDate)
            ->selectRaw('customer_id, COUNT(*) as completed_visits')
            ->groupBy('customer_id')
            ->pluck('completed_visits', 'customer_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    private function customerSegment(int $businessId, int $completedVisits): string
    {
        return $this->classification->typeFor($businessId, $completedVisits)['name'];
    }

    private function customerLifetimeValuesThrough(User $actor, Collection $customerIds, string $throughDate): Collection
    {
        if ($customerIds->isEmpty()) return collect();

        $sales = Sale::query()
            ->where('business_id', $actor->business_id)
            ->whereIn('customer_id', $customerIds->all())
            ->whereNotNull('financial_finalized_at')
            ->where('status', '<>', 'REVERSED')
            ->whereDate('sale_date', '<=', $throughDate)
            ->with(['payments' => fn ($query) => $query
                ->where('status', 'RECEIVED')
                ->where('payment_type', '!=', 'TIP')
                ->whereDate('payment_date', '<=', $throughDate)])
            ->get();

        return $customerIds->mapWithKeys(function ($customerId) use ($sales) {
            $value = 0.0;
            foreach ($sales->where('customer_id', $customerId) as $sale) {
                $received = (float) $sale->payments->sum('amount');
                $saleValue = (float) ($sale->effective_net_amount ?? $sale->net_amount ?? $sale->gross_amount);
                $value += min($saleValue, $received);
            }

            return [(int) $customerId => round($value, 2)];
        });
    }

    private function comparisonRange(string $from, string $to, bool $compareEnabled, ?string $compareFrom, ?string $compareTo): array
    {
        $timezone = $this->clock->timezone();
        $fromDate = CarbonImmutable::parse($from, $timezone);
        $toDate = CarbonImmutable::parse($to, $timezone);

        if ($compareEnabled) {
            $manualFrom = $this->date($compareFrom, $fromDate->subMonthNoOverflow()->toDateString());
            $manualTo = $this->date($compareTo, $toDate->subMonthNoOverflow()->toDateString());
            if ($manualFrom > $manualTo) {
                throw new DomainException('Compare From date cannot be after Compare To date.');
            }
            return [$manualFrom, $manualTo, 'manual'];
        }

        return [
            $fromDate->subMonthNoOverflow()->toDateString(),
            $toDate->subMonthNoOverflow()->toDateString(),
            'previous_month_same_range',
        ];
    }

    private function comparisonLabel(string $from, string $to): string
    {
        $timezone = $this->clock->timezone();
        $fromDate = CarbonImmutable::parse($from, $timezone);
        $toDate = CarbonImmutable::parse($to, $timezone);

        if ($from === $to) {
            return $fromDate->format('M j, Y');
        }

        return $fromDate->format('M j, Y').' to '.$toDate->format('M j, Y');
    }

    private function summaryChanges(array $current, array $comparison): array
    {
        $metrics = [
            'transactions', 'gross_sales', 'discounts', 'net_sales', 'payments_received', 'cash_refunds',
            'net_cash_collections', 'refund_adjustments_posted', 'outstanding_balance', 'average_transaction', 'average_booking_value', 'average_lifetime_value', 'client_visits', 'unique_customers',
        ];

        return collect($metrics)->mapWithKeys(function (string $metric) use ($current, $comparison) {
            $currentValue = (float) ($current[$metric] ?? 0);
            $comparisonValue = (float) ($comparison[$metric] ?? 0);
            $difference = round($currentValue - $comparisonValue, 2);
            $percent = $comparisonValue != 0.0 ? round(($difference / $comparisonValue) * 100, 1) : null;
            return [$metric => [
                'current' => $currentValue,
                'comparison' => $comparisonValue,
                'difference' => $difference,
                'percent' => $percent,
            ]];
        })->all();
    }

    private function branches(User $actor)
    {
        return Branch::query()->where('business_id', $actor->business_id)->where('status', 'ACTIVE')
            ->when($actor->default_branch_id, fn ($q) => $q->whereKey($actor->default_branch_id))
            ->orderBy('name')->get(['id', 'code', 'name'])->values();
    }

    private function branchId(User $actor, int $branchId): int
    {
        if ($actor->default_branch_id && (int) $actor->default_branch_id !== $branchId) {
            throw new DomainException('Select a branch available to your account.');
        }

        $branch = Branch::query()->where('business_id', $actor->business_id)->where('status', 'ACTIVE')->find($branchId);
        if (! $branch) throw new DomainException('Select a valid active branch.');
        return (int) $branch->id;
    }

    private function date(mixed $value, string $fallback): string
    {
        try {
            return CarbonImmutable::parse($value ?: $fallback, $this->clock->timezone())->toDateString();
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
