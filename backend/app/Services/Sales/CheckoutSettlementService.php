<?php

namespace App\Services\Sales;

use App\Models\AccountingPaymentMethodMapping;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\OpeningCustomerReceivable;
use App\Models\Payment;
use App\Models\PaymentApplication;
use App\Models\Personnel;
use App\Models\PackageEnrollment;
use App\Models\ReferenceValue;
use App\Models\RetailProduct;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StaffTip;
use App\Models\User;
use App\Services\Commissions\CommissionEarningService;
use App\Services\Incentives\IncentiveEarningService;
use App\Services\Discounts\DiscountService;
use App\Services\Foundation\BusinessClock;
use App\Services\Foundation\CodeSequenceService;
use App\Services\Packages\PackageServiceCreditService;
use App\Services\Security\AuditService;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CheckoutSettlementService
{
    private const EPSILON = 0.009;

    public function __construct(
        private readonly CodeSequenceService $codes,
        private readonly BusinessClock $clock,
        private readonly AuditService $audit,
        private readonly PaymentLedgerService $paymentLedger,
        private readonly PackageServiceCreditService $packages,
        private readonly DiscountService $discounts,
        private readonly CommissionEarningService $commissionEarnings,
        private readonly IncentiveEarningService $incentiveEarnings,
    ) {
    }

    public function checkoutData(User $actor, Sale $sale, string|array|null $discountCode = null): array
    {
        $this->assertOwned($actor, $sale);
        if (! $sale->isPendingFinancialFinalization()) {
            throw new DomainException('This transaction has already been financially finalized.');
        }

        $sale = $this->loadSale($sale);
        $discountPreview = $this->discounts->preview($actor, $sale, $discountCode);
        $discountRows = collect($discountPreview['items'])->keyBy('sale_item_id')->all();
        $applications = $this->activeApplicationsByItem($sale);
        $items = $sale->items
            ->reject(fn (SaleItem $item) => in_array(strtoupper((string) ($item->correction_status ?? 'ACTIVE')), ['REMOVED', 'REVERSED'], true))
            ->map(fn (SaleItem $item) => $this->checkoutItemFinancialRow(
                $actor,
                $sale,
                $item,
                $applications,
                $discountRows[$item->id] ?? null,
            ))
            ->values();

        $bookingInterimConfirmations = $this->bookingInterimConfirmationsByProxy($sale, $items);
        $items = $items
            ->map(function (array $row) use ($bookingInterimConfirmations) {
                $row['booking_interim_confirmation_amount'] = round((float) ($bookingInterimConfirmations[$row['id']] ?? 0), 2);
                return $row;
            })
            ->values();

        $advanceSources = $this->paymentLedger->advanceSourcesForSale($sale);
        $advanceRows = $advanceSources->map(function (array $source) {
            /** @var Payment $payment */
            $payment = $source['payment'];
            return [
                'id' => $payment->id,
                'code' => $payment->code,
                'amount' => (float) $source['reserved_amount'],
                'applied_amount' => (float) $source['used_amount'],
                'unapplied_amount' => (float) $source['available'],
                'method' => $payment->payment_method_snapshot,
                'reference_no' => $payment->reference_no,
                'payment_date' => $payment->payment_date?->toIso8601String(),
            ];
        })->values();

        $chargeableBalance = round((float) $items->where('chargeable', true)->sum('balance_amount'), 2);
        $serviceCreditPackageCapacity = round((float) $items
            ->filter(fn (array $row) => ! $row['chargeable'] && ($row['service_credit_interim_eligible'] ?? false))
            ->unique('service_credit_target_sale_item_id')
            ->sum('service_credit_package_balance'), 2);
        $advanceAvailable = round((float) $advanceRows->sum('unapplied_amount'), 2);
        $advanceRequired = min($advanceAvailable, round($chargeableBalance + $serviceCreditPackageCapacity, 2));

        return [
            'sale' => $sale,
            'items' => $items,
            'advance_payments' => $advanceRows,
            'advance_available' => $advanceAvailable,
            'advance_required_allocation' => $advanceRequired,
            'booking_interim_confirmation_required' => round((float) collect($bookingInterimConfirmations)->sum(), 2),
            'current_chargeable_balance' => $chargeableBalance,
            'discount' => [
                'code' => $discountPreview['discount_code'],
                'codes' => $discountPreview['promotion_codes'] ?? [],
                'gross_amount' => $discountPreview['gross_amount'],
                'discount_amount' => $discountPreview['discount_amount'],
                'net_amount' => $discountPreview['net_amount'],
            ],
            'payment_methods' => $this->paymentMethods($actor->business_id),
            'tip_personnel' => Personnel::query()
                ->where('business_id', $actor->business_id)
                ->where('status', 'ACTIVE')
                ->orderBy('last_name')->orderBy('first_name')
                ->get()
                ->map(fn (Personnel $personnel) => ['id' => $personnel->id, 'code' => $personnel->code, 'name' => $personnel->full_name])
                ->values()->all(),
        ];
    }

    public function finalize(User $actor, Sale $sale, array $payload): Sale
    {
        $this->assertOwned($actor, $sale);

        return DB::transaction(function () use ($actor, $sale, $payload) {
            $sale = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if (! $sale->isPendingFinancialFinalization()) {
                throw new DomainException('This transaction has already been financially finalized.');
            }

            $sale->load(['booking', 'customer', 'items.service', 'payments']);
            $discountResult = $this->discounts->apply($actor, $sale, $payload['discount_codes'] ?? ($payload['discount_code'] ?? null));
            $sale = $sale->fresh();
            $sale->load(['booking', 'customer', 'items.service', 'payments']);
            $applications = $this->activeApplicationsByItem($sale);
            $itemBalances = [];
            $chargeableTotal = 0.0;

            foreach ($sale->items as $item) {
                $row = $this->checkoutItemFinancialRow($actor, $sale, $item, $applications);
                $itemBalances[$item->id] = $row;
                if ($row['chargeable']) $chargeableTotal += $row['balance_amount'];
            }
            $chargeableTotal = round($chargeableTotal, 2);
            $bookingInterimConfirmations = $this->bookingInterimConfirmationsByProxy(
                $sale,
                collect(array_values($itemBalances)),
            );
            $bookingInterimConfirmationRequired = round((float) collect($bookingInterimConfirmations)->sum(), 2);

            $advanceSources = $this->paymentLedger->advanceSourcesForSale($sale);
            $advanceAvailable = round((float) $advanceSources->sum('available'), 2);
            $serviceCreditPackageCapacity = round((float) collect($itemBalances)
                ->filter(fn (array $row) => ! $row['chargeable'] && ($row['service_credit_interim_eligible'] ?? false))
                ->unique('service_credit_target_sale_item_id')
                ->sum('service_credit_package_balance'), 2);
            $advanceRequired = min($advanceAvailable, round($chargeableTotal + $serviceCreditPackageCapacity, 2));

            $hasCollectedAllocations = array_key_exists('collected_allocations', $payload);
            if ($bookingInterimConfirmationRequired > self::EPSILON && ! $hasCollectedAllocations) {
                throw new DomainException('Allocate the Booking Interim collection in the Collected column before finalizing.');
            }

            $advanceInputRows = $hasCollectedAllocations
                ? $this->advanceRowsFromCollectedAllocations(
                    $payload['collected_allocations'] ?? [],
                    $itemBalances,
                    $bookingInterimConfirmations,
                )
                : ($payload['advance_allocations'] ?? []);

            $advanceAllocationSets = $this->normalizeCheckoutAllocations(
                $advanceInputRows,
                $actor,
                $sale,
                $itemBalances,
                'Advance',
            );
            $advanceAllocations = $advanceAllocationSets['current'];
            $serviceCreditAdvanceAllocations = $advanceAllocationSets['interim'];
            $advanceCurrentSaleSum = $this->sumAllocations($advanceAllocations);
            $advanceInterimSum = $this->sumAllocations($serviceCreditAdvanceAllocations);
            $advanceSum = round($advanceCurrentSaleSum + $advanceInterimSum, 2);
            $this->assertMoneyEquals($advanceSum, $advanceRequired, 'Advance allocation must equal the advance amount currently required for this transaction.');

            $amountNow = $this->money($payload['amount_collected_now'] ?? 0);
            $tipAmount = $this->money($payload['tip_amount'] ?? 0);
            $tipPersonnel = null;
            $tipMethodId = (int) ($payload['tip_payment_method_reference_id'] ?? 0);

            if (($amountNow + $tipAmount) > self::EPSILON && ! $actor->hasPermission('payments', 'CREATE')) {
                throw new DomainException('You do not have permission to receive a Checkout Payment.');
            }

            if ($tipAmount > self::EPSILON) {
                $tipPersonnel = Personnel::query()
                    ->where('business_id', $actor->business_id)
                    ->where('status', 'ACTIVE')
                    ->whereKey((int) ($payload['tip_personnel_id'] ?? 0))
                    ->first();
                if (! $tipPersonnel) {
                    throw new DomainException('Select an active Personnel for the electronic tip.');
                }
                if ($tipMethodId <= 0) {
                    throw new DomainException('Select which bank/e-wallet Payment Method received the tip.');
                }
            }

            $checkoutAllocationSets = $this->normalizeCheckoutAllocations(
                $payload['checkout_allocations'] ?? [],
                $actor,
                $sale,
                $itemBalances,
                'Checkout',
            );
            $checkoutAllocations = $checkoutAllocationSets['current'];
            $serviceCreditInterimAllocations = $checkoutAllocationSets['interim'];
            $checkoutCurrentSaleSum = $this->sumAllocations($checkoutAllocations);
            $checkoutInterimSum = $this->sumAllocations($serviceCreditInterimAllocations);
            $checkoutSum = round($checkoutCurrentSaleSum + $checkoutInterimSum, 2);

            $this->assertMoneyEquals(
                $checkoutSum,
                $amountNow,
                'Checkout allocations for this Booking must equal Amount Collected Now.',
            );

            $combinedByItem = [];
            foreach ([$advanceAllocations, $checkoutAllocations] as $allocationSet) {
                foreach ($allocationSet as $allocation) {
                    $combinedByItem[$allocation['sale_item_id']] = round(($combinedByItem[$allocation['sale_item_id']] ?? 0) + $allocation['amount'], 2);
                }
            }

            foreach ($combinedByItem as $itemId => $amount) {
                $balance = (float) ($itemBalances[$itemId]['balance_amount'] ?? 0);
                if ($amount - $balance > self::EPSILON) {
                    throw new DomainException('An allocation exceeds the remaining balance of one of the transaction lines.');
                }
            }

            $packageInterimByTarget = [];
            foreach ([$serviceCreditAdvanceAllocations, $serviceCreditInterimAllocations] as $allocationSet) {
                foreach ($allocationSet as $allocation) {
                    $targetItemId = (int) $allocation['sale_item_id'];
                    if (! isset($packageInterimByTarget[$targetItemId])) {
                        $targetSale = Sale::query()->findOrFail((int) $allocation['target_sale_id']);
                        $targetItem = SaleItem::query()->whereKey($targetItemId)
                            ->where('sale_id', $targetSale->id)
                            ->firstOrFail();
                        $targetFinancial = $this->itemFinancialRow($targetItem, $this->activeApplicationsByItem($targetSale));
                        $packageInterimByTarget[$targetItemId] = [
                            'allocated' => 0.0,
                            'balance' => (float) $targetFinancial['balance_amount'],
                        ];
                    }
                    $packageInterimByTarget[$targetItemId]['allocated'] = round(
                        $packageInterimByTarget[$targetItemId]['allocated'] + (float) $allocation['amount'],
                        2,
                    );
                }
            }

            foreach ($packageInterimByTarget as $target) {
                if ($target['allocated'] - $target['balance'] > self::EPSILON) {
                    throw new DomainException('Combined Advance and Checkout payment exceeds the remaining package balance behind a Service Credit claim.');
                }
            }

            $nonInstallmentOutstanding = 0.0;
            foreach ($itemBalances as $itemId => $row) {
                if (! $row['chargeable']) continue;

                $staged = (float) ($combinedByItem[$itemId] ?? 0);
                $projectedLineBalance = max(0, round((float) $row['balance_amount'] - $staged, 2));
                if ($projectedLineBalance > self::EPSILON && ! $row['installment_eligible']) {
                    $nonInstallmentOutstanding = round($nonInstallmentOutstanding + $projectedLineBalance, 2);
                }
            }

            if ($nonInstallmentOutstanding > self::EPSILON) {
                throw new DomainException('Single-session services and product purchases must be fully paid at checkout. Only multi-session packages may carry a remaining balance.');
            }

            $checkoutPayments = collect();
            $tipPayment = null;
            $tipPaymentMapping = null;
            $staffTip = null;
            if (($amountNow + $tipAmount) > self::EPSILON) {
                $rawIdempotencyKey = trim((string) ($payload['idempotency_key'] ?? ''));
                if ($rawIdempotencyKey === '') {
                    throw new DomainException('A checkout idempotency key is required.');
                }

                $paymentPlan = $this->normalizeCheckoutPaymentSplits($actor, $payload, $amountNow, $tipAmount, $tipMethodId);

                foreach ($paymentPlan['checkout'] as $index => $split) {
                    $checkoutPayments->push(Payment::query()->create([
                        'business_id' => $sale->business_id,
                        'code' => $this->nextPaymentCode($sale->business_id),
                        'customer_id' => $sale->customer_id,
                        'booking_id' => $sale->booking_id,
                        'sale_id' => $sale->id,
                        'visit_id' => $sale->visit_id,
                        'payment_type' => 'CHECKOUT',
                        'amount' => $split['amount'],
                        'payment_method_reference_id' => $split['method']->id,
                        'payment_method_snapshot' => $split['method']->display_value,
                        'reference_no' => $split['reference_no'],
                        'payment_date' => $this->clock->now(),
                        'status' => 'RECEIVED',
                        'idempotency_key' => 'CHECKOUT:'.hash('sha256', $rawIdempotencyKey.':'.($index + 1)),
                        'created_by' => $actor->id,
                    ]));
                }

                if ($paymentPlan['tip']) {
                    $tipSplit = $paymentPlan['tip'];
                    $tipPaymentMapping = $tipSplit['mapping'];
                    $tipPayment = Payment::query()->create([
                        'business_id' => $sale->business_id,
                        'code' => $this->nextPaymentCode($sale->business_id),
                        'customer_id' => $sale->customer_id,
                        'booking_id' => $sale->booking_id,
                        'sale_id' => $sale->id,
                        'visit_id' => $sale->visit_id,
                        'payment_type' => 'TIP',
                        'amount' => $tipSplit['amount'],
                        'payment_method_reference_id' => $tipSplit['method']->id,
                        'payment_method_snapshot' => $tipSplit['method']->display_value,
                        'reference_no' => $tipSplit['reference_no'],
                        'payment_date' => $this->clock->now(),
                        'status' => 'RECEIVED',
                        'idempotency_key' => 'TIP:'.hash('sha256', $rawIdempotencyKey),
                        'created_by' => $actor->id,
                    ]);
                }
            }

            $this->applyAcrossPayments($actor, $sale, $advanceSources, $advanceAllocations, 'ADVANCE');

            if ($serviceCreditAdvanceAllocations) {
                $remainingAdvanceSources = $this->paymentLedger->advanceSourcesForSale($sale);
                $this->applyAcrossPaymentsToOnAccountTargets(
                    $actor,
                    $sale,
                    $remainingAdvanceSources,
                    $serviceCreditAdvanceAllocations,
                    'SERVICE_CREDIT_INTERIM_ADVANCE',
                );
            }

            if ($checkoutPayments->isNotEmpty()) {
                $checkoutSources = $this->checkoutPaymentSources($checkoutPayments);
                $this->applyAcrossPayments(
                    $actor,
                    $sale,
                    $checkoutSources,
                    $checkoutAllocations,
                    'CHECKOUT',
                    'CHECKOUT_LINE',
                );

                if ($serviceCreditInterimAllocations) {
                    $remainingCheckoutSources = $this->checkoutPaymentSources($checkoutPayments);
                    $this->applyAcrossPaymentsToOnAccountTargets(
                        $actor,
                        $sale,
                        $remainingCheckoutSources,
                        $serviceCreditInterimAllocations,
                        'SERVICE_CREDIT_INTERIM_CHECKOUT',
                    );
                }
            }

            if ($tipPayment && $tipPersonnel && $tipPaymentMapping) {
                $staffTip = $this->createStaffTip($actor, $sale, $tipPayment, $tipPersonnel, $tipPaymentMapping, $tipAmount);
            }

            $affectedPackageSales = collect($serviceCreditAdvanceAllocations)
                ->concat($serviceCreditInterimAllocations)
                ->pluck('target_sale_id')
                ->filter()
                ->unique()
                ->values();

            foreach ($affectedPackageSales as $targetSaleId) {
                $targetSale = Sale::query()->findOrFail($targetSaleId);
                $this->reconcileSale($actor, $targetSale, false);
            }

            $sale = $this->reconcileSale($actor, $sale, true);
            $retailInventoryPosting = $this->postBookingRetailInventory($actor, $sale);
            $this->packages->activateSalePackages($actor, $sale);
            $sale = $this->loadSale($sale->fresh());
            $commissionEarnings = $this->commissionEarnings->reconcileSale($actor, $sale);
            $incentiveEarnings = $this->incentiveEarnings->reconcileSale($actor, $sale);

            $this->audit->record(
                action: 'FINALIZE_CHECKOUT',
                module: 'sales',
                entityType: Sale::class,
                entityId: $sale->id,
                after: $this->saleSnapshot($sale),
                actor: $actor,
                metadata: [
                    'advance_allocated' => $advanceSum,
                    'advance_current_sale_allocated' => $advanceCurrentSaleSum,
                    'advance_interim_allocated' => $advanceInterimSum,
                    'booking_interim_confirmed_at_checkout' => $bookingInterimConfirmationRequired,
                    'checkout_received' => $amountNow,
                    'electronic_tip_received' => $tipAmount,
                    'staff_tip_id' => $staffTip?->id,
                    'checkout_current_sale_allocated' => $checkoutCurrentSaleSum,
                    'checkout_interim_allocated' => $checkoutInterimSum,
                    'remaining_balance' => (float) $sale->outstanding_balance,
                    'discount_code' => $discountResult['discount_code'],
                    'discount_codes' => $discountResult['promotion_codes'] ?? [],
                    'discount_amount' => $discountResult['discount_amount'],
                    'net_amount' => $discountResult['net_amount'],
                    'commission_phase_active' => true,
                    'commission_earnings_count' => $commissionEarnings->where('status', '!=', 'REVERSED')->count(),
                    'incentive_earnings_count' => $incentiveEarnings->count(),
                    'retail_inventory_movements_posted' => $retailInventoryPosting['posted_count'],
                    'retail_inventory_movements_already_posted' => $retailInventoryPosting['already_posted_count'],
                ],
            );

            return $sale;
        });
    }

    public function collectionData(User $actor, Sale $sale): array
    {
        $this->assertOwned($actor, $sale);
        $sale = $this->loadSale($sale);
        if (! $sale->financial_finalized_at || strtoupper((string) $sale->status) !== 'ON_ACCOUNT') {
            throw new DomainException('Only finalized On Account transactions can receive a collection.');
        }

        $applications = $this->activeApplicationsByItem($sale);
        $items = $sale->items
            ->reject(fn (SaleItem $item) => in_array(strtoupper((string) ($item->correction_status ?? 'ACTIVE')), ['REMOVED', 'REVERSED'], true))
            ->map(fn (SaleItem $item) => $this->itemFinancialRow($item, $applications))
            ->where('chargeable', true)
            ->where('balance_amount', '>', self::EPSILON)
            ->values();

        return [
            'sale' => $sale,
            'items' => $items,
            'payment_methods' => $this->paymentMethods($actor->business_id),
        ];
    }

    public function collect(User $actor, Sale $sale, array $payload): Sale
    {
        $this->assertOwned($actor, $sale);
        if (! $actor->hasPermission('payments', 'CREATE')) {
            throw new DomainException('You do not have permission to receive an On Account Collection.');
        }

        return DB::transaction(function () use ($actor, $sale, $payload) {
            $sale = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if (! $sale->financial_finalized_at || strtoupper((string) $sale->status) !== 'ON_ACCOUNT') {
                throw new DomainException('Only finalized On Account transactions can receive a collection.');
            }

            $sale->load(['booking', 'customer', 'items']);
            $applications = $this->activeApplicationsByItem($sale);
            $itemBalances = [];
            foreach ($sale->items as $item) {
                if (in_array(strtoupper((string) ($item->correction_status ?? 'ACTIVE')), ['REMOVED', 'REVERSED'], true)) continue;
                $itemBalances[$item->id] = $this->itemFinancialRow($item, $applications);
            }

            $amount = $this->money($payload['amount_collected'] ?? 0);
            if ($amount <= self::EPSILON) throw new DomainException('Amount Collected must be greater than zero.');
            if ($amount - (float) $sale->outstanding_balance > self::EPSILON) {
                throw new DomainException('Amount Collected cannot exceed the transaction balance.');
            }

            $allocations = $this->normalizeAllocations($payload['allocations'] ?? [], $sale, $itemBalances, 'Collection');
            $allocationSum = $this->sumAllocations($allocations);
            $this->assertMoneyEquals($allocationSum, $amount, 'Collection allocation must equal Amount Collected.');

            foreach ($allocations as $allocation) {
                $balance = (float) ($itemBalances[$allocation['sale_item_id']]['balance_amount'] ?? 0);
                if ($allocation['amount'] - $balance > self::EPSILON) {
                    throw new DomainException('A collection allocation exceeds the remaining line balance.');
                }
            }

            $method = $this->paymentMethod($actor->business_id, (int) ($payload['payment_method_reference_id'] ?? 0));
            $rawIdempotencyKey = trim((string) ($payload['idempotency_key'] ?? ''));
            if ($rawIdempotencyKey === '') throw new DomainException('A collection idempotency key is required.');
            $idempotencyKey = 'COLLECTION:'.hash('sha256', $rawIdempotencyKey);

            $payment = Payment::query()->create([
                'business_id' => $sale->business_id,
                'code' => $this->nextPaymentCode($sale->business_id),
                'customer_id' => $sale->customer_id,
                'booking_id' => $sale->booking_id,
                'sale_id' => $sale->id,
                'visit_id' => $sale->visit_id,
                'payment_type' => 'COLLECTION',
                'amount' => $amount,
                'payment_method_reference_id' => $method->id,
                'payment_method_snapshot' => $method->display_value,
                'reference_no' => $this->nullableTrim($payload['reference_no'] ?? null),
                'payment_date' => $this->clock->now(),
                'status' => 'RECEIVED',
                'idempotency_key' => $idempotencyKey,
                'created_by' => $actor->id,
            ]);

            $this->applySinglePayment($actor, $sale, $payment, $allocations, 'COLLECTION');
            $sale = $this->reconcileSale($actor, $sale, false);

            $this->audit->record(
                action: 'RECEIVE_ON_ACCOUNT_COLLECTION',
                module: 'sales',
                entityType: Sale::class,
                entityId: $sale->id,
                after: $this->saleSnapshot($sale),
                actor: $actor,
                metadata: ['payment_id' => $payment->id, 'payment_code' => $payment->code, 'amount' => $amount],
            );

            return $sale;
        });
    }


    public function onAccountCustomers(User $actor, ?string $search = null): Collection
    {
        $openingCustomerIds = OpeningCustomerReceivable::query()
            ->where('business_id', $actor->business_id)
            ->where('status', 'OPEN')
            ->where('remaining_balance', '>', self::EPSILON)
            ->when($actor->default_branch_id, fn ($query) => $query->where('branch_id', $actor->default_branch_id))
            ->pluck('customer_id');

        $query = Customer::query()
            ->where('business_id', $actor->business_id)
            ->where(function ($customerQuery) use ($actor, $openingCustomerIds) {
                $customerQuery->whereHas('bookings.sale', function ($saleQuery) use ($actor) {
                    $saleQuery->whereNotNull('financial_finalized_at')
                        ->where('status', 'ON_ACCOUNT')
                        ->where('outstanding_balance', '>', self::EPSILON);
                    if ($actor->default_branch_id) $saleQuery->where('branch_id', $actor->default_branch_id);
                });
                if ($openingCustomerIds->isNotEmpty()) $customerQuery->orWhereIn('id', $openingCustomerIds);
            });

        $search = trim((string) $search);
        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('mobile_number', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('first_name')->orderBy('last_name')->get()->map(function (Customer $customer) use ($actor) {
            $sales = Sale::query()
                ->where('business_id', $actor->business_id)
                ->where('customer_id', $customer->id)
                ->whereNotNull('financial_finalized_at')
                ->where('status', 'ON_ACCOUNT')
                ->where('outstanding_balance', '>', self::EPSILON);
            if ($actor->default_branch_id) $sales->where('branch_id', $actor->default_branch_id);

            $opening = OpeningCustomerReceivable::query()
                ->where('business_id', $actor->business_id)
                ->where('customer_id', $customer->id)
                ->where('status', 'OPEN')
                ->where('remaining_balance', '>', self::EPSILON);
            if ($actor->default_branch_id) $opening->where('branch_id', $actor->default_branch_id);

            $openingTotal = round((float) $opening->sum('remaining_balance'), 2);
            return [
                'id' => $customer->id,
                'code' => $customer->code,
                'name' => $customer->full_name,
                'mobile_number' => $customer->mobile_number,
                'open_bookings' => (int) $sales->count() + ($openingTotal > self::EPSILON ? 1 : 0),
                'outstanding_balance' => round((float) $sales->sum('outstanding_balance') + $openingTotal, 2),
            ];
        })->values();
    }

    public function customerOnAccountData(User $actor, Customer $customer): array
    {
        if ((int) $customer->business_id !== (int) $actor->business_id) abort(404);

        $query = Sale::query()
            ->with(['booking', 'items'])
            ->where('business_id', $actor->business_id)
            ->where('customer_id', $customer->id)
            ->whereNotNull('financial_finalized_at')
            ->where('status', 'ON_ACCOUNT')
            ->where('outstanding_balance', '>', self::EPSILON);
        if ($actor->default_branch_id) $query->where('branch_id', $actor->default_branch_id);

        $sales = $query->orderBy('sale_date')->orderBy('id')->get();
        $bookings = $sales->map(function (Sale $sale) {
            $applications = $this->activeApplicationsByItem($sale);
            $lines = $sale->items
                ->map(function (SaleItem $item) use ($applications) {
                    $row = $this->itemFinancialRow($item, $applications);
                    $row['allocation_key'] = 'sale:'.$item->id;
                    $row['sale_item_id'] = $item->id;
                    $row['allocation_source'] = 'SALE_ITEM';
                    return $row;
                })
                ->where('chargeable', true)
                ->where('balance_amount', '>', self::EPSILON)
                ->values();

            return [
                'sale_id' => $sale->id,
                'sale_code' => $sale->code,
                'booking_id' => $sale->booking_id,
                'booking_code' => $sale->booking?->code,
                'booking_source' => $sale->booking?->booking_source,
                'transaction_date' => $sale->sale_date?->toIso8601String(),
                'outstanding_balance' => round((float) $lines->sum('balance_amount'), 2),
                'items' => $lines,
            ];
        })->filter(fn (array $row) => $row['outstanding_balance'] > self::EPSILON)->values();

        $openingQuery = OpeningCustomerReceivable::query()
            ->with(['packageEnrollment'])
            ->where('business_id', $actor->business_id)
            ->where('customer_id', $customer->id)
            ->where('status', 'OPEN')
            ->where('remaining_balance', '>', self::EPSILON);
        if ($actor->default_branch_id) $openingQuery->where('branch_id', $actor->default_branch_id);

        $openingRows = $openingQuery->orderBy('cutover_date')->orderBy('id')->get();
        if ($openingRows->isNotEmpty()) {
            $openingItems = $openingRows->map(function (OpeningCustomerReceivable $row) {
                $itemName = $row->packageEnrollment?->service_name_snapshot
                    ? $row->packageEnrollment->service_name_snapshot.' · Opening Package Balance'
                    : ($row->reference ?: 'Opening Customer Balance');
                return [
                    'id' => 'opening-'.$row->id,
                    'allocation_key' => 'opening:'.$row->id,
                    'allocation_source' => 'OPENING_RECEIVABLE',
                    'opening_receivable_id' => $row->id,
                    'item_name' => $itemName,
                    'balance_amount' => (float) $row->remaining_balance,
                    'chargeable' => true,
                ];
            })->values();

            $bookings->push([
                'sale_id' => null,
                'sale_code' => 'OPENING',
                'booking_id' => 'opening-'.$openingRows->first()->branch_id,
                'booking_code' => 'Opening Balance',
                'booking_source' => 'OPENING',
                'transaction_date' => $openingRows->first()->cutover_date?->startOfDay()->toIso8601String(),
                'outstanding_balance' => round((float) $openingRows->sum('remaining_balance'), 2),
                'items' => $openingItems,
            ]);
        }

        return [
            'customer' => [
                'id' => $customer->id,
                'code' => $customer->code,
                'name' => $customer->full_name,
                'mobile_number' => $customer->mobile_number,
            ],
            'bookings' => $bookings,
            'outstanding_balance' => round((float) $bookings->sum('outstanding_balance'), 2),
            'payment_methods' => $this->paymentMethods($actor->business_id),
        ];
    }

    public function receiveBookingPaymentReceipt(User $actor, Booking $sourceBooking, array $payload): Payment
    {
        if ((int) $sourceBooking->business_id !== (int) $actor->business_id) abort(404);
        if (! $actor->hasPermission('payments', 'CREATE')) {
            throw new DomainException('You do not have permission to receive a payment.');
        }

        return DB::transaction(function () use ($actor, $sourceBooking, $payload) {
            $sourceBooking = Booking::query()->whereKey($sourceBooking->id)->lockForUpdate()->firstOrFail();
            if ((int) $sourceBooking->business_id !== (int) $actor->business_id) abort(404);

            $customer = Customer::query()->whereKey($sourceBooking->customer_id)->firstOrFail();
            $receiptAmount = $this->money($payload['amount'] ?? 0);
            $advanceAmount = $this->money($payload['advance_amount'] ?? 0);
            if ($receiptAmount <= self::EPSILON) throw new DomainException('Payment received must be greater than zero.');
            if ($advanceAmount < 0) throw new DomainException('Advance allocation cannot be negative.');

            $rows = collect($payload['allocations'] ?? [])
                ->map(fn ($row) => [
                    'sale_item_id' => isset($row['sale_item_id']) && (int)$row['sale_item_id'] > 0 ? (int)$row['sale_item_id'] : null,
                    'opening_receivable_id' => isset($row['opening_receivable_id']) && (int)$row['opening_receivable_id'] > 0 ? (int)$row['opening_receivable_id'] : null,
                    'amount' => $this->money($row['amount'] ?? 0),
                ])
                ->filter(fn ($row) => $row['amount'] > self::EPSILON)
                ->values();

            foreach ($rows as $row) {
                $targets = ($row['sale_item_id'] ? 1 : 0) + ($row['opening_receivable_id'] ? 1 : 0);
                if ($targets !== 1) throw new DomainException('Each Interim allocation must target exactly one On Account line or Opening Balance.');
            }
            $keys = $rows->map(fn ($row) => $row['sale_item_id'] ? 'sale:'.$row['sale_item_id'] : 'opening:'.$row['opening_receivable_id']);
            if ($keys->duplicates()->isNotEmpty()) throw new DomainException('The same customer balance cannot be allocated more than once.');

            $saleRows = $rows->filter(fn ($row) => $row['sale_item_id'])->values();
            $openingRows = $rows->filter(fn ($row) => $row['opening_receivable_id'])->values();

            $affectedSales = [];
            $interimTotal = 0.0;
            $items = collect();
            if ($saleRows->isNotEmpty()) {
                $items = SaleItem::query()
                    ->with(['sale.booking', 'sale.customer'])
                    ->whereIn('id', $saleRows->pluck('sale_item_id'))
                    ->lockForUpdate()->get()->keyBy('id');

                foreach ($saleRows as $row) {
                    /** @var SaleItem|null $item */
                    $item = $items->get($row['sale_item_id']);
                    if (! $item || ! $item->sale) throw new DomainException('An Interim allocation points to an invalid service line.');

                    $sale = $item->sale;
                    if ((int) $sale->business_id !== (int) $actor->business_id || (int) $sale->customer_id !== (int) $customer->id) {
                        throw new DomainException('An Interim allocation belongs to another customer or business.');
                    }
                    if ((int) $sale->branch_id !== (int) $sourceBooking->branch_id) {
                        throw new DomainException('An Interim allocation belongs to another branch.');
                    }
                    if (! $sale->financial_finalized_at || strtoupper((string) $sale->status) !== 'ON_ACCOUNT') {
                        throw new DomainException('Interim allocations can only be applied to finalized On Account bookings.');
                    }

                    $financial = $this->itemFinancialRow($item, $this->activeApplicationsByItem($sale));
                    if (! $financial['chargeable'] || $financial['balance_amount'] <= self::EPSILON) {
                        throw new DomainException('One of the selected service lines no longer has an On Account balance.');
                    }
                    if ($row['amount'] - (float) $financial['balance_amount'] > self::EPSILON) {
                        throw new DomainException('An Interim allocation exceeds a service line balance.');
                    }

                    $interimTotal = round($interimTotal + $row['amount'], 2);
                    $affectedSales[$sale->id] = $sale;
                }
            }

            $openingReceivables = collect();
            if ($openingRows->isNotEmpty()) {
                $openingReceivables = OpeningCustomerReceivable::query()
                    ->with('packageEnrollment')
                    ->whereIn('id', $openingRows->pluck('opening_receivable_id'))
                    ->lockForUpdate()->get()->keyBy('id');

                foreach ($openingRows as $row) {
                    /** @var OpeningCustomerReceivable|null $receivable */
                    $receivable = $openingReceivables->get($row['opening_receivable_id']);
                    if (! $receivable) throw new DomainException('An Interim allocation points to an invalid Opening Balance.');
                    if ((int)$receivable->business_id !== (int)$actor->business_id || (int)$receivable->customer_id !== (int)$customer->id) {
                        throw new DomainException('An Opening Balance allocation belongs to another customer or business.');
                    }
                    if ((int)$receivable->branch_id !== (int)$sourceBooking->branch_id) {
                        throw new DomainException('An Opening Balance allocation belongs to another branch.');
                    }
                    if (strtoupper((string)$receivable->status) !== 'OPEN' || (float)$receivable->remaining_balance <= self::EPSILON) {
                        throw new DomainException('One of the selected Opening Balances is already settled.');
                    }
                    if ($row['amount'] - (float)$receivable->remaining_balance > self::EPSILON) {
                        throw new DomainException('An Interim allocation exceeds an Opening Balance.');
                    }
                    $interimTotal = round($interimTotal + $row['amount'], 2);
                }
            }

            $allocatedTotal = round($advanceAmount + $interimTotal, 2);
            $this->assertMoneyEquals($allocatedTotal, $receiptAmount, 'Advance plus Interim allocations must equal the total payment received.');
            if ($advanceAmount <= self::EPSILON && $interimTotal <= self::EPSILON) {
                throw new DomainException('Allocate the payment to the new Booking, an On Account balance, an Opening Balance, or a combination of them.');
            }

            $method = $this->paymentMethod($actor->business_id, (int) ($payload['payment_method_reference_id'] ?? 0));
            $idempotencyKey = 'BOOKING_RECEIPT:'.$sourceBooking->id;
            if ($existing = Payment::query()->where('idempotency_key', $idempotencyKey)->first()) {
                return $existing->fresh(['applications']);
            }

            $paymentType = $advanceAmount > self::EPSILON && $interimTotal > self::EPSILON
                ? 'MIXED'
                : ($advanceAmount > self::EPSILON ? 'ADVANCE' : 'INTERIM');

            $payment = Payment::query()->create([
                'business_id' => $actor->business_id,
                'code' => $this->paymentLedger->nextCode($actor->business_id),
                'customer_id' => $customer->id,
                'booking_id' => $sourceBooking->id,
                'sale_id' => null,
                'visit_id' => null,
                'payment_type' => $paymentType,
                'amount' => $receiptAmount,
                'payment_method_reference_id' => $method->id,
                'payment_method_snapshot' => $method->display_value,
                'reference_no' => $this->nullableTrim($payload['reference_no'] ?? null),
                'payment_date' => $this->clock->now(),
                'status' => 'RECEIVED',
                'idempotency_key' => $idempotencyKey,
                'notes' => $this->nullableTrim($payload['notes'] ?? null),
                'created_by' => $actor->id,
            ]);

            $order = 10;
            if ($advanceAmount > self::EPSILON) {
                $this->createAdvanceReserveApplication($actor, $sourceBooking, $payment, $advanceAmount, $order);
                $order += 10;
            }

            foreach ($saleRows as $row) {
                $item = $items->get($row['sale_item_id']);
                $sale = $affectedSales[$item->sale_id];
                $this->createApplication($actor, $sale, $payment, $item->id, $row['amount'], $order, 'INTERIM_PAYMENT', 'ON_ACCOUNT_LINE');
                $order += 10;
            }

            foreach ($openingRows as $row) {
                $receivable = $openingReceivables->get($row['opening_receivable_id']);
                $this->createOpeningReceivableApplication($actor, $sourceBooking, $payment, $receivable, $row['amount'], $order);
                $order += 10;
            }

            foreach ($affectedSales as $sale) $this->reconcileSale($actor, $sale, false);

            $this->audit->record(
                action: 'RECEIVE_BOOKING_PAYMENT', module: 'payments', entityType: Payment::class, entityId: $payment->id,
                after: [
                    'payment_code' => $payment->code,
                    'source_booking_id' => $sourceBooking->id,
                    'source_booking_code' => $sourceBooking->code,
                    'customer_id' => $customer->id,
                    'amount' => $receiptAmount,
                    'advance_amount' => $advanceAmount,
                    'interim_amount' => $interimTotal,
                    'payment_type' => $paymentType,
                    'payment_method' => $method->display_value,
                ],
                actor: $actor,
                metadata: [
                    'source_booking_id' => $sourceBooking->id,
                    'source_booking_code' => $sourceBooking->code,
                    'applied_booking_codes' => collect($affectedSales)->map(fn (Sale $sale) => $sale->booking?->code)->filter()->values()->all(),
                    'opening_receivable_codes' => $openingRows->map(fn ($row) => $openingReceivables->get($row['opening_receivable_id'])?->code)->filter()->values()->all(),
                    'allocation_count' => $rows->count() + ($advanceAmount > self::EPSILON ? 1 : 0),
                ],
            );

            return $payment->fresh(['applications']);
        });
    }

    private function createOpeningReceivableApplication(
        User $actor,
        Booking $sourceBooking,
        Payment $payment,
        OpeningCustomerReceivable $receivable,
        float $amount,
        int $order,
    ): PaymentApplication {
        $key = $payment->id.':OPENING_AR:'.$receivable->id;
        if ($existing = PaymentApplication::query()->where('application_key', $key)->first()) return $existing;

        $now = $this->clock->now();
        return PaymentApplication::query()->create([
            'code' => $this->codes->next('PAYMENT_APPLICATION:'.$now->format('my'), 'PAP-'.$now->format('my').'-', 7, $actor->business_id),
            'application_key' => $key,
            'payment_id' => $payment->id,
            'sale_id' => null,
            'target_booking_id' => null,
            'target_type' => 'OPENING_AR',
            'target_sale_item_id' => null,
            'package_enrollment_id' => $receivable->package_enrollment_id,
            'opening_customer_receivable_id' => $receivable->id,
            'source_sale_id' => null,
            'amount' => $this->money($amount),
            'allocation_order' => $order,
            'status' => 'ACTIVE',
            'target_snapshot' => [
                'source' => 'OPENING_RECEIVABLE',
                'source_booking_code' => $sourceBooking->code,
                'opening_receivable_code' => $receivable->code,
                'balance_type' => $receivable->balance_type,
                'reference' => $receivable->reference,
                'package_code' => $receivable->packageEnrollment?->code,
            ],
            'created_by' => $actor->id,
        ]);
    }

    private function createAdvanceReserveApplication(User $actor, Booking $booking, Payment $payment, float $amount, int $order): PaymentApplication
    {
        $key = $payment->id.':ADVANCE_RESERVE:'.$booking->id;
        if ($existing = PaymentApplication::query()->where('application_key', $key)->first()) return $existing;
        $now = $this->clock->now();

        return PaymentApplication::query()->create([
            'code' => $this->codes->next('PAYMENT_APPLICATION:'.$now->format('my'), 'PAP-'.$now->format('my').'-', 7, $booking->business_id),
            'application_key' => $key,
            'payment_id' => $payment->id,
            'sale_id' => null,
            'target_booking_id' => $booking->id,
            'target_type' => 'ADVANCE_RESERVE',
            'target_sale_item_id' => null,
            'amount' => $this->money($amount),
            'allocation_order' => $order,
            'status' => 'ACTIVE',
            'target_snapshot' => [
                'source' => 'BOOKING_PAYMENT',
                'booking_code' => $booking->code,
                'purpose' => 'ADVANCE_RESERVE',
            ],
            'created_by' => $actor->id,
        ]);
    }

    public function receiveInterimPayment(User $actor, Customer $customer, array $payload): Payment
    {
        if ((int) $customer->business_id !== (int) $actor->business_id) {
            abort(404);
        }
        if (! $actor->hasPermission('payments', 'CREATE')) {
            throw new DomainException('You do not have permission to receive an Interim Payment.');
        }

        return DB::transaction(function () use ($actor, $customer, $payload) {
            $rows = collect($payload['allocations'] ?? [])
                ->map(fn ($row) => [
                    'sale_item_id' => (int) ($row['sale_item_id'] ?? 0),
                    'amount' => $this->money($row['amount'] ?? 0),
                ])
                ->filter(fn ($row) => $row['amount'] > self::EPSILON)
                ->values();

            if ($rows->isEmpty()) {
                throw new DomainException('Allocate an Interim Payment amount to at least one On Account service line.');
            }
            if ($rows->pluck('sale_item_id')->duplicates()->isNotEmpty()) {
                throw new DomainException('The same On Account line cannot be allocated more than once.');
            }

            $items = SaleItem::query()
                ->with(['sale.booking', 'sale.customer'])
                ->whereIn('id', $rows->pluck('sale_item_id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $affectedSales = [];
            $total = 0.0;
            foreach ($rows as $row) {
                /** @var SaleItem|null $item */
                $item = $items->get($row['sale_item_id']);
                if (! $item || ! $item->sale) {
                    throw new DomainException('An Interim Payment allocation points to an invalid service line.');
                }
                $sale = $item->sale;
                if ((int) $sale->business_id !== (int) $actor->business_id || (int) $sale->customer_id !== (int) $customer->id) {
                    throw new DomainException('An Interim Payment allocation belongs to another customer or business.');
                }
                if ($actor->default_branch_id && (int) $sale->branch_id !== (int) $actor->default_branch_id) {
                    throw new DomainException('An Interim Payment allocation belongs to another branch.');
                }
                if (! $sale->financial_finalized_at || strtoupper((string) $sale->status) !== 'ON_ACCOUNT') {
                    throw new DomainException('Interim Payments can only be applied to finalized On Account bookings.');
                }

                $applications = $this->activeApplicationsByItem($sale);
                $financial = $this->itemFinancialRow($item, $applications);
                if (! $financial['chargeable'] || $financial['balance_amount'] <= self::EPSILON) {
                    throw new DomainException('One of the selected service lines no longer has an On Account balance.');
                }
                if ($row['amount'] - (float) $financial['balance_amount'] > self::EPSILON) {
                    throw new DomainException('An Interim Payment allocation exceeds a service line balance.');
                }

                $total = round($total + $row['amount'], 2);
                $affectedSales[$sale->id] = $sale;
            }

            if ($total <= self::EPSILON) {
                throw new DomainException('Interim Payment must be greater than zero.');
            }

            $method = $this->paymentMethod($actor->business_id, (int) ($payload['payment_method_reference_id'] ?? 0));
            $rawIdempotencyKey = trim((string) ($payload['idempotency_key'] ?? ''));
            if ($rawIdempotencyKey === '') {
                throw new DomainException('An Interim Payment idempotency key is required.');
            }

            $payment = Payment::query()->create([
                'business_id' => $actor->business_id,
                'code' => $this->nextPaymentCode($actor->business_id),
                'customer_id' => $customer->id,
                'booking_id' => null,
                'sale_id' => null,
                'visit_id' => null,
                'payment_type' => 'COLLECTION',
                'amount' => $total,
                'payment_method_reference_id' => $method->id,
                'payment_method_snapshot' => $method->display_value,
                'reference_no' => $this->nullableTrim($payload['reference_no'] ?? null),
                'payment_date' => $this->clock->now(),
                'status' => 'RECEIVED',
                'idempotency_key' => 'INTERIM:'.hash('sha256', $rawIdempotencyKey),
                'created_by' => $actor->id,
            ]);

            $order = 10;
            foreach ($rows as $row) {
                $item = $items->get($row['sale_item_id']);
                $sale = $affectedSales[$item->sale_id];
                $this->createApplication($actor, $sale, $payment, $item->id, $row['amount'], $order, 'INTERIM_PAYMENT', 'ON_ACCOUNT_LINE');
                $order += 10;
            }

            foreach ($affectedSales as $sale) {
                $this->reconcileSale($actor, $sale, false);
            }

            $this->audit->record(
                action: 'RECEIVE_INTERIM_PAYMENT',
                module: 'sales',
                entityType: Payment::class,
                entityId: $payment->id,
                after: [
                    'payment_code' => $payment->code,
                    'customer_id' => $customer->id,
                    'amount' => $total,
                    'payment_method' => $method->display_value,
                ],
                actor: $actor,
                metadata: [
                    'booking_codes' => collect($affectedSales)->map(fn (Sale $sale) => $sale->booking?->code)->filter()->values()->all(),
                    'sale_ids' => array_keys($affectedSales),
                    'allocation_count' => $rows->count(),
                ],
            );

            return $payment->fresh(['applications']);
        });
    }

    public function settlementLines(User $actor, string $classification, ?string $search = null): Collection
    {
        $classification = strtoupper(trim($classification));
        if (! in_array($classification, ['PAID', 'ON_ACCOUNT', 'SERVICE_CREDIT'], true)) {
            throw new DomainException('Unsupported settlement classification.');
        }

        $candidateStatuses = match ($classification) {
            'PAID' => ['PAID', 'SERVICE_CREDIT'],
            'ON_ACCOUNT' => ['ON_ACCOUNT', 'SERVICE_CREDIT', 'PAID'],
            default => ['SERVICE_CREDIT'],
        };

        $query = Sale::query()
            ->with(['booking', 'customer', 'items.packageEnrollment.originSaleItem'])
            ->where('business_id', $actor->business_id)
            ->whereNotNull('financial_finalized_at')
            ->whereIn('status', $candidateStatuses);

        if ($actor->default_branch_id) {
            $query->where('branch_id', $actor->default_branch_id);
        }

        $search = trim((string) $search);
        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('code', 'like', "%{$search}%")
                    ->orWhereHas('booking', fn ($q) => $q->where('code', 'like', "%{$search}%"))
                    ->orWhereHas('items', fn ($q) => $q->where('item_name_snapshot', 'like', "%{$search}%"))
                    ->orWhereHas('customer', function ($q) use ($search) {
                        $q->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('mobile_number', 'like', "%{$search}%");
                    });
            });
        }

        return $query
            ->orderByDesc('financial_finalized_at')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (Sale $sale) => $this->transactionClassification($sale) === $classification)
            ->values();
    }

    public function transactionClassification(Sale $sale): string
    {
        $status = strtoupper((string) $sale->status);
        $sale->loadMissing('items');

        $hasServiceCredit = $sale->items
            ->contains(fn (SaleItem $item) => strtoupper((string) $item->item_type) === 'SERVICE_CREDIT_USE');

        if (! $hasServiceCredit) {
            return $status;
        }

        $context = $this->serviceCreditPackagePaymentContext($sale);
        if (! $context['has_package_payment']) {
            return $status;
        }

        if ($context['package_balance'] > self::EPSILON) {
            return 'ON_ACCOUNT';
        }

        return $status === 'ON_ACCOUNT' ? 'ON_ACCOUNT' : 'PAID';
    }

    public function serviceCreditPackagePaymentContext(Sale $sale): array
    {
        $sale->loadMissing(['items.packageEnrollment.originSaleItem']);

        $enrollmentIds = $sale->items
            ->filter(fn (SaleItem $item) => strtoupper((string) $item->item_type) === 'SERVICE_CREDIT_USE')
            ->pluck('package_enrollment_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($enrollmentIds->isEmpty()) {
            return ['has_package_payment' => false, 'package_balance' => 0.0];
        }

        $enrollments = PackageEnrollment::query()
            ->with('originSaleItem')
            ->whereIn('id', $enrollmentIds)
            ->get();

        $originItemIds = $enrollments
            ->pluck('origin_sale_item_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $packageBalance = round((float) $enrollments
            ->map(fn (PackageEnrollment $enrollment) => (float) ($enrollment->originSaleItem?->balance_amount ?? 0))
            ->sum(), 2);

        if (! $sale->booking_id || $originItemIds->isEmpty()) {
            return ['has_package_payment' => false, 'package_balance' => $packageBalance];
        }

        $paymentIds = Payment::query()
            ->where('booking_id', $sale->booking_id)
            ->where('status', 'RECEIVED')
            ->pluck('id');

        if ($paymentIds->isEmpty()) {
            return ['has_package_payment' => false, 'package_balance' => $packageBalance];
        }

        $hasPackagePayment = PaymentApplication::query()
            ->whereIn('payment_id', $paymentIds)
            ->where('status', 'ACTIVE')
            ->where('target_type', 'ON_ACCOUNT_LINE')
            ->whereIn('target_sale_item_id', $originItemIds)
            ->exists();

        return [
            'has_package_payment' => $hasPackagePayment,
            'package_balance' => $packageBalance,
        ];
    }

    private function reconcileSale(User $actor, Sale $sale, bool $finalizing): Sale
    {
        $sale = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
        $sale->load('items');
        $applications = $this->activeApplicationsByItem($sale);
        $now = $this->clock->now();
        $appliedTotal = 0.0;
        $outstanding = 0.0;
        $discountTotal = 0.0;
        $netTotal = 0.0;
        $chargeableCount = 0;
        $serviceCreditCount = 0;

        foreach ($sale->items as $item) {
            $removed = in_array(strtoupper((string) ($item->correction_status ?? 'ACTIVE')), ['REMOVED', 'REVERSED'], true);
            if ($removed) {
                $activeApplied = round((float) ($applications[$item->id] ?? 0), 2);
                $existingRefundDue = max(0, round((float) ($item->refund_due_amount ?? 0), 2));
                $refundDue = max($existingRefundDue, $activeApplied);
                $refundStatus = $refundDue > self::EPSILON
                    ? 'DUE'
                    : (((float) ($item->cash_refunded_amount ?? 0) > self::EPSILON) ? 'FULL' : ($item->refund_status ?? 'NONE'));

                $item->update([
                    'applied_amount' => 0,
                    'balance_amount' => 0,
                    'effective_net_line_total' => 0,
                    // A removed/reversed line may still represent money already collected.
                    // Never erase that liability while another On Account line is being collected.
                    'refund_due_amount' => $refundDue,
                    'refund_status' => $refundStatus,
                    'status' => strtoupper((string) $item->correction_status) === 'REVERSED' ? 'REVERSED' : 'CORRECTED_REMOVED',
                    'financial_finalized_at' => $item->financial_finalized_at ?: $now,
                    'updated_by' => $actor->id,
                ]);
                continue;
            }

            $isServiceCredit = strtoupper((string) $item->item_type) === 'SERVICE_CREDIT_USE';
            if ($isServiceCredit) {
                $serviceCreditCount++;
                $item->update([
                    'discount_rule_id' => null,
                    'discount_code_snapshot' => null,
                    'discount_rule_name_snapshot' => null,
                    'discount_calculation_type_snapshot' => null,
                    'discount_rate_value_snapshot' => null,
                    'discount_amount' => 0,
                    'net_line_total' => 0,
                    'effective_net_line_total' => 0,
                    'refund_due_amount' => 0,
                    'applied_amount' => 0,
                    'balance_amount' => 0,
                    'status' => 'SERVICE_CREDIT',
                    'financial_finalized_at' => $item->financial_finalized_at ?: $now,
                    'updated_by' => $actor->id,
                ]);
                continue;
            }

            $chargeableCount++;
            $effectiveLineTotal = max(0, round((float) ($item->effective_net_line_total ?? $item->net_line_total ?? $item->line_total), 2));
            $discountAmount = round((float) ($item->discount_amount ?? 0), 2);
            $applied = min($effectiveLineTotal, round((float) ($applications[$item->id] ?? 0), 2));
            $balance = max(0, round($effectiveLineTotal - $applied, 2));
            $refundDue = max(0, round((float) ($item->refund_due_amount ?? 0), 2));
            $status = $balance <= self::EPSILON ? 'PAID' : 'ON_ACCOUNT';
            $appliedTotal += $applied;
            $outstanding += $balance;
            $discountTotal += $discountAmount;
            $netTotal += $effectiveLineTotal;

            $item->update([
                'applied_amount' => round($applied, 2),
                'balance_amount' => round($balance, 2),
                'effective_net_line_total' => $effectiveLineTotal,
                'refund_due_amount' => $refundDue,
                'status' => $status,
                'financial_finalized_at' => $item->financial_finalized_at ?: $now,
                'updated_by' => $actor->id,
            ]);
        }

        $advance = $this->receivedPaymentTotal($sale, 'ADVANCE');
        $checkout = $this->receivedPaymentTotal($sale, 'CHECKOUT');
        $collection = $this->receivedPaymentTotal($sale, 'COLLECTION');
        $interim = $this->receivedPaymentTotal($sale, 'INTERIM');
        $received = round($advance + $checkout + $collection + $interim, 2);
        $outstanding = round($outstanding, 2);
        $refundDueTotal = round((float) $sale->items->sum(fn (SaleItem $item) => max(0, (float) ($item->refund_due_amount ?? 0))), 2);

        $status = $chargeableCount === 0 && $serviceCreditCount > 0
            ? 'SERVICE_CREDIT'
            : ($outstanding <= self::EPSILON ? 'PAID' : 'ON_ACCOUNT');

        $sale->update([
            'discount_amount' => round($discountTotal, 2),
            'net_amount' => round($netTotal, 2),
            'effective_net_amount' => round($netTotal, 2),
            'refund_due_amount' => $refundDueTotal,
            'refund_status' => $refundDueTotal > self::EPSILON ? 'DUE' : ($sale->refund_status ?? 'NONE'),
            'advance_payments' => $advance,
            'checkout_payments' => $checkout,
            'total_paid' => $received,
            'applied_amount' => round($appliedTotal, 2),
            'outstanding_balance' => $outstanding,
            'status' => $status,
            'financial_finalized_at' => $sale->financial_finalized_at ?: ($finalizing ? $now : $sale->financial_finalized_at),
            'updated_by' => $actor->id,
        ]);

        return $this->loadSale($sale->fresh());
    }

    private function applyAcrossPayments(
        User $actor,
        Sale $sale,
        Collection $sources,
        array $allocations,
        string $sourceLabel,
        string $targetType = 'ADVANCE_LINE',
    ): void
    {
        $pool = $sources->map(fn (array $source) => [
            'payment' => $source['payment'],
            'available' => (float) $source['available'],
        ])->values()->all();

        $order = 10;
        foreach ($allocations as $allocation) {
            $remaining = $allocation['amount'];
            if ($remaining <= self::EPSILON) continue;

            foreach ($pool as &$source) {
                if ($remaining <= self::EPSILON) break;
                if ($source['available'] <= self::EPSILON) continue;
                $amount = min($remaining, $source['available']);
                $this->createApplication($actor, $sale, $source['payment'], $allocation['sale_item_id'], $amount, $order, $sourceLabel, $targetType);
                $source['available'] = round($source['available'] - $amount, 2);
                $remaining = round($remaining - $amount, 2);
                $order += 10;
            }
            unset($source);

            if ($remaining > self::EPSILON) throw new DomainException($sourceLabel.' allocation exceeds the available Payment amount.');
        }
    }

    private function applySinglePayment(User $actor, Sale $sale, Payment $payment, array $allocations, string $sourceLabel, string $targetType = 'CURRENT_SALE_LINE'): void
    {
        $order = 10;
        foreach ($allocations as $allocation) {
            if ($allocation['amount'] <= self::EPSILON) continue;
            $this->createApplication($actor, $sale, $payment, $allocation['sale_item_id'], $allocation['amount'], $order, $sourceLabel, $targetType);
            $order += 10;
        }
    }

    private function createApplication(
        User $actor,
        Sale $sale,
        Payment $payment,
        int $itemId,
        float $amount,
        int $order,
        string $sourceLabel,
        string $targetType = 'CURRENT_SALE_LINE',
        ?string $applicationKeySuffix = null,
        ?int $sourceSaleId = null,
        ?int $packageEnrollmentId = null,
    ): PaymentApplication {
        $item = $sale->items->firstWhere('id', $itemId) ?: SaleItem::query()->where('sale_id', $sale->id)->findOrFail($itemId);
        $key = $payment->id.':'.$targetType.':'.$itemId.($applicationKeySuffix ? ':'.$applicationKeySuffix : '');
        $existing = PaymentApplication::query()->where('application_key', $key)->first();
        if ($existing) return $existing;

        $now = $this->clock->now();
        return PaymentApplication::query()->create([
            'code' => $this->codes->next('PAYMENT_APPLICATION:'.$now->format('my'), 'PAP-'.$now->format('my').'-', 7, $sale->business_id),
            'application_key' => $key,
            'payment_id' => $payment->id,
            'sale_id' => $sale->id,
            'target_type' => $targetType,
            'target_sale_item_id' => $itemId,
            'package_enrollment_id' => $packageEnrollmentId,
            'source_sale_id' => $sourceSaleId,
            'amount' => $this->money($amount),
            'allocation_order' => $order,
            'status' => 'ACTIVE',
            'target_snapshot' => [
                'source' => $sourceLabel,
                'source_booking_code' => $payment->booking?->code,
                'booking_code' => $sale->booking?->code,
                'sale_item_code' => $item->code,
                'item_name' => $item->item_name_snapshot,
                'item_type' => $item->item_type,
            ],
            'created_by' => $actor->id,
        ]);
    }

    private function applyAcrossPaymentsToOnAccountTargets(
        User $actor,
        Sale $sourceSale,
        Collection $sources,
        array $allocations,
        string $sourceLabel,
    ): void {
        $pool = $sources->map(fn (array $source) => [
            'payment' => $source['payment'],
            'available' => (float) $source['available'],
        ])->values()->all();

        foreach ($allocations as $allocation) {
            $remaining = (float) $allocation['amount'];
            if ($remaining <= self::EPSILON) continue;

            $targetSale = Sale::query()->whereKey((int) $allocation['target_sale_id'])->lockForUpdate()->firstOrFail();

            foreach ($pool as &$source) {
                if ($remaining <= self::EPSILON) break;
                if ($source['available'] <= self::EPSILON) continue;

                $amount = min($remaining, $source['available']);
                $this->createApplication(
                    $actor,
                    $targetSale,
                    $source['payment'],
                    (int) $allocation['sale_item_id'],
                    $amount,
                    2000 + (int) ($allocation['allocation_order'] ?? 0),
                    $sourceLabel,
                    'ON_ACCOUNT_LINE',
                    $sourceLabel,
                    $sourceSale->id,
                    $allocation['package_enrollment_id'] ?? null,
                );

                $source['available'] = round($source['available'] - $amount, 2);
                $remaining = round($remaining - $amount, 2);
            }
            unset($source);

            if ($remaining > self::EPSILON) {
                throw new DomainException('Payment allocation exceeds the available Payment amount.');
            }
        }
    }

    private function normalizeCheckoutAllocations(array $rows, User $actor, Sale $sale, array $itemBalances, string $label = 'Checkout'): array
    {
        $current = [];
        $interimByTargetItem = [];
        $seenProxyItems = [];
        $order = 10;

        foreach ($rows as $row) {
            $itemId = (int) ($row['sale_item_id'] ?? 0);
            $amount = $this->money($row['amount'] ?? 0);

            if ($itemId <= 0) throw new DomainException("{$label} allocation has an invalid Sale Item.");
            if (! isset($itemBalances[$itemId])) throw new DomainException("{$label} allocation contains an item outside this transaction.");
            if (isset($seenProxyItems[$itemId])) throw new DomainException("{$label} allocation contains the same line more than once.");
            $seenProxyItems[$itemId] = true;

            $financial = $itemBalances[$itemId];
            if ($financial['chargeable']) {
                $current[] = ['sale_item_id' => $itemId, 'amount' => $amount];
                continue;
            }

            if ($amount <= self::EPSILON) continue;
            if (! ($financial['service_credit_interim_eligible'] ?? false)) {
                throw new DomainException("This Service Credit does not have an eligible On Account package balance for {$label} payment.");
            }

            $targetItemId = (int) ($financial['service_credit_target_sale_item_id'] ?? 0);
            $targetSaleId = (int) ($financial['service_credit_target_sale_id'] ?? 0);
            if ($targetItemId <= 0 || $targetSaleId <= 0) {
                throw new DomainException('The Service Credit package payment target is unavailable.');
            }

            $targetSale = Sale::query()->whereKey($targetSaleId)->lockForUpdate()->first();
            $targetItem = SaleItem::query()->whereKey($targetItemId)->where('sale_id', $targetSaleId)->lockForUpdate()->first();
            if (! $targetSale || ! $targetItem) throw new DomainException('The Service Credit package payment target no longer exists.');
            if ((int) $targetSale->business_id !== (int) $actor->business_id || (int) $targetSale->customer_id !== (int) $sale->customer_id) {
                throw new DomainException('The Service Credit package balance belongs to another customer or business.');
            }
            if ($actor->default_branch_id && (int) $targetSale->branch_id !== (int) $actor->default_branch_id) {
                throw new DomainException('The Service Credit package balance belongs to another branch.');
            }
            if (! $targetSale->financial_finalized_at || strtoupper((string) $targetSale->status) !== 'ON_ACCOUNT') {
                throw new DomainException('The Service Credit package no longer has an On Account balance.');
            }

            $targetFinancial = $this->itemFinancialRow($targetItem, $this->activeApplicationsByItem($targetSale));
            $targetBalance = (float) $targetFinancial['balance_amount'];
            $existing = (float) ($interimByTargetItem[$targetItemId]['amount'] ?? 0);
            if (($existing + $amount) - $targetBalance > self::EPSILON) {
                throw new DomainException('Interim allocation through Service Credit exceeds the remaining package balance.');
            }

            if (! isset($interimByTargetItem[$targetItemId])) {
                $interimByTargetItem[$targetItemId] = [
                    'sale_item_id' => $targetItemId,
                    'target_sale_id' => $targetSaleId,
                    'package_enrollment_id' => (int) ($financial['service_credit_package_enrollment_id'] ?? 0) ?: null,
                    'amount' => 0.0,
                    'allocation_order' => $order,
                ];
                $order += 10;
            }
            $interimByTargetItem[$targetItemId]['amount'] = round($interimByTargetItem[$targetItemId]['amount'] + $amount, 2);
        }

        return [
            'current' => $current,
            'interim' => array_values($interimByTargetItem),
        ];
    }

    private function bookingInterimConfirmationsByProxy(Sale $sale, Collection $items): array
    {
        if (! $sale->booking_id) return [];

        $proxyByTarget = [];
        foreach ($items as $row) {
            if (strtoupper((string) ($row['item_type'] ?? '')) !== 'SERVICE_CREDIT_USE') continue;

            $proxyItemId = (int) ($row['id'] ?? 0);
            $targetItemId = (int) ($row['service_credit_target_sale_item_id'] ?? 0);
            if ($proxyItemId <= 0 || $targetItemId <= 0) continue;

            if (! isset($proxyByTarget[$targetItemId])) {
                $proxyByTarget[$targetItemId] = $proxyItemId;
            }
        }

        if (! $proxyByTarget) return [];

        $paymentIds = Payment::query()
            ->where('booking_id', $sale->booking_id)
            ->where('status', 'RECEIVED')
            ->pluck('id');

        if ($paymentIds->isEmpty()) return [];

        $applications = PaymentApplication::query()
            ->whereIn('payment_id', $paymentIds)
            ->where('status', 'ACTIVE')
            ->where('target_type', 'ON_ACCOUNT_LINE')
            ->whereIn('target_sale_item_id', array_keys($proxyByTarget))
            ->whereNull('source_sale_id')
            ->where('target_snapshot->source', 'INTERIM_PAYMENT')
            ->get();

        $byProxy = [];
        foreach ($applications as $application) {
            $targetItemId = (int) $application->target_sale_item_id;
            $proxyItemId = (int) ($proxyByTarget[$targetItemId] ?? 0);
            if ($proxyItemId <= 0) continue;

            $byProxy[$proxyItemId] = round(
                (float) ($byProxy[$proxyItemId] ?? 0) + (float) $application->amount,
                2,
            );
        }

        return $byProxy;
    }

    private function advanceRowsFromCollectedAllocations(
        array $rows,
        array $itemBalances,
        array $bookingInterimConfirmations,
    ): array {
        $seen = [];
        $entered = [];

        foreach ($rows as $row) {
            $itemId = (int) ($row['sale_item_id'] ?? 0);
            $amount = $this->money($row['amount'] ?? 0);

            if ($itemId <= 0) throw new DomainException('Collected allocation has an invalid Sale Item.');
            if (! isset($itemBalances[$itemId])) throw new DomainException('Collected allocation contains an item outside this transaction.');
            if (isset($seen[$itemId])) throw new DomainException('Collected allocation contains the same line more than once.');

            $seen[$itemId] = true;
            $entered[$itemId] = $amount;
        }

        foreach ($bookingInterimConfirmations as $proxyItemId => $requiredAmount) {
            $enteredAmount = (float) ($entered[$proxyItemId] ?? 0);
            if ($enteredAmount + self::EPSILON < (float) $requiredAmount) {
                throw new DomainException('Allocate the Booking Interim collection to the matching Service Credit line in the Collected column before finalizing.');
            }
        }

        $advanceRows = [];
        foreach ($entered as $itemId => $enteredAmount) {
            $confirmationAmount = (float) ($bookingInterimConfirmations[$itemId] ?? 0);
            $advanceAmount = max(0, round($enteredAmount - $confirmationAmount, 2));
            $advanceRows[] = [
                'sale_item_id' => (int) $itemId,
                'amount' => $advanceAmount,
            ];
        }

        return $advanceRows;
    }

    private function checkoutItemFinancialRow(User $actor, Sale $sale, SaleItem $item, array $applications, ?array $discountPreview = null): array
    {
        $row = $this->itemFinancialRow($item, $applications);
        if ($discountPreview && $row['chargeable']) {
            $row['discount_rule'] = $discountPreview['rule'] ?? null;
            $row['discount_amount'] = round((float) ($discountPreview['discount_amount'] ?? 0), 2);
            $row['net_line_total'] = round((float) ($discountPreview['net_amount'] ?? $row['line_total']), 2);
            $row['balance_amount'] = max(0, round($row['net_line_total'] - (float) $row['applied_amount'], 2));
        }
        $row['service_credit_interim_eligible'] = false;
        $row['service_credit_package_balance'] = 0.0;
        $row['service_credit_package_booking_code'] = null;
        $row['service_credit_target_sale_item_id'] = null;
        $row['service_credit_target_sale_id'] = null;
        $row['service_credit_package_enrollment_id'] = null;

        if (strtoupper((string) $item->item_type) !== 'SERVICE_CREDIT_USE' || ! $item->package_enrollment_id) {
            return $row;
        }

        $enrollment = PackageEnrollment::query()
            ->with(['originSaleItem.sale.booking'])
            ->find($item->package_enrollment_id);
        $targetItem = $enrollment?->originSaleItem;
        $targetSale = $targetItem?->sale;

        if (! $targetItem || ! $targetSale) return $row;
        if ((int) $targetSale->business_id !== (int) $actor->business_id || (int) $targetSale->customer_id !== (int) $sale->customer_id) return $row;
        if ($actor->default_branch_id && (int) $targetSale->branch_id !== (int) $actor->default_branch_id) return $row;

        $row['service_credit_package_booking_code'] = $targetSale->booking?->code;
        $row['service_credit_target_sale_item_id'] = $targetItem->id;
        $row['service_credit_target_sale_id'] = $targetSale->id;
        $row['service_credit_package_enrollment_id'] = $enrollment->id;

        if (! $targetSale->financial_finalized_at) return $row;

        $targetFinancial = $this->itemFinancialRow($targetItem, $this->activeApplicationsByItem($targetSale));
        $targetBalance = (float) $targetFinancial['balance_amount'];
        $row['service_credit_package_balance'] = round($targetBalance, 2);

        if (strtoupper((string) $targetSale->status) === 'ON_ACCOUNT' && $targetBalance > self::EPSILON) {
            $row['service_credit_interim_eligible'] = true;
        }

        return $row;
    }

    private function normalizeAllocations(array $rows, Sale $sale, array $itemBalances, string $label): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            $itemId = (int) ($row['sale_item_id'] ?? 0);
            $amount = $this->money($row['amount'] ?? 0);
            if ($itemId <= 0) throw new DomainException("{$label} allocation has an invalid Sale Item.");
            if (! isset($itemBalances[$itemId])) throw new DomainException("{$label} allocation contains an item outside this transaction.");
            if (! $itemBalances[$itemId]['chargeable'] && $amount > self::EPSILON) {
                throw new DomainException('Service Credit lines cannot receive current-sale payment allocation.');
            }
            if (isset($normalized[$itemId])) throw new DomainException("{$label} allocation contains the same line more than once.");
            $normalized[$itemId] = ['sale_item_id' => $itemId, 'amount' => $amount];
        }
        return array_values($normalized);
    }

    private function itemFinancialRow(SaleItem $item, array $applications): array
    {
        $serviceCredit = strtoupper((string) $item->item_type) === 'SERVICE_CREDIT_USE';
        $gross = round((float) $item->line_total, 2);
        $discount = $serviceCredit ? 0.0 : round((float) ($item->discount_amount ?? 0), 2);
        $net = $serviceCredit ? 0.0 : max(0, round((float) ($item->effective_net_line_total ?? $item->net_line_total ?? $gross), 2));
        $applied = $serviceCredit ? 0.0 : round((float) ($applications[$item->id] ?? 0), 2);
        $balance = $serviceCredit ? 0.0 : max(0, round($net - $applied, 2));

        return [
            'id' => $item->id,
            'code' => $item->code,
            'item_type' => $item->item_type,
            'item_name' => $item->item_name_snapshot,
            'assigned_personnel_name' => $item->assigned_personnel_name_snapshot,
            'quantity' => (int) $item->quantity,
            'unit_price' => (float) $item->unit_price,
            'line_total' => $gross,
            'gross_line_total' => $gross,
            'discount_amount' => $discount,
            'net_line_total' => $net,
            'discount_rule' => $item->discount_rule_id ? [
                'id' => $item->discount_rule_id,
                'code' => $item->discount_code_snapshot,
                'rule_name' => $item->discount_rule_name_snapshot,
                'calculation_type' => $item->discount_calculation_type_snapshot,
                'rate_value' => $item->discount_rate_value_snapshot !== null ? (float) $item->discount_rate_value_snapshot : null,
            ] : null,
            'applied_amount' => $applied,
            'balance_amount' => $balance,
            'chargeable' => ! $serviceCredit,
            'installment_eligible' => strtoupper((string) $item->item_type) === 'PACKAGE',
            'status' => $item->status,
        ];
    }

    private function activeApplicationsByItem(Sale $sale): array
    {
        return PaymentApplication::query()
            ->where('sale_id', $sale->id)
            ->whereIn('target_type', ['CURRENT_SALE_LINE', 'ADVANCE_LINE', 'CHECKOUT_LINE', 'ON_ACCOUNT_LINE'])
            ->where('status', 'ACTIVE')
            ->whereNotNull('target_sale_item_id')
            ->selectRaw('target_sale_item_id, SUM(amount) as applied')
            ->groupBy('target_sale_item_id')
            ->pluck('applied', 'target_sale_item_id')
            ->map(fn ($value) => round((float) $value, 2))
            ->all();
    }

    private function receivedPaymentTotal(Sale $sale, string $type): float
    {
        $query = PaymentApplication::query()
            ->join('payments', 'payments.id', '=', 'payment_applications.payment_id')
            ->where('payment_applications.sale_id', $sale->id)
            ->where('payment_applications.status', 'ACTIVE')
            ->where('payments.status', 'RECEIVED');

        $type = strtoupper($type);
        if ($type === 'ADVANCE') {
            $query->where(function ($q) {
                $q->where('payment_applications.target_type', 'ADVANCE_LINE')
                    ->orWhere(function ($legacy) {
                        $legacy->where('payment_applications.target_type', 'CURRENT_SALE_LINE')
                            ->where('payments.payment_type', 'ADVANCE');
                    });
            });
        } elseif ($type === 'CHECKOUT') {
            $query->where(function ($q) {
                $q->where('payment_applications.target_type', 'CHECKOUT_LINE')
                    ->orWhere(function ($legacy) {
                        $legacy->where('payment_applications.target_type', 'CURRENT_SALE_LINE')
                            ->where('payments.payment_type', 'CHECKOUT');
                    });
            });
        } elseif (in_array($type, ['COLLECTION', 'INTERIM'], true)) {
            if ($type === 'COLLECTION') {
                $query->where('payment_applications.target_type', 'ON_ACCOUNT_LINE')
                    ->where('payments.payment_type', 'COLLECTION');
            } else {
                $query->where('payment_applications.target_type', 'ON_ACCOUNT_LINE')
                    ->whereIn('payments.payment_type', ['INTERIM', 'MIXED', 'CHECKOUT', 'ADVANCE']);
            }
        } else {
            $query->where('payments.payment_type', $type);
        }

        return round((float) $query->sum('payment_applications.amount'), 2);
    }


    private function normalizeCheckoutPaymentSplits(
        User $actor,
        array $payload,
        float $amountNow,
        float $tipAmount = 0.0,
        int $tipMethodId = 0,
    ): array {
        $expectedTotal = round($amountNow + $tipAmount, 2);
        $rows = array_key_exists('payment_splits', $payload)
            ? ($payload['payment_splits'] ?? [])
            : [[
                'payment_method_reference_id' => $payload['payment_method_reference_id'] ?? null,
                'amount' => $expectedTotal,
                'reference_no' => $payload['reference_no'] ?? null,
            ]];

        if (! $rows) {
            throw new DomainException('Add at least one Payment Method for the amount received at Checkout.');
        }

        $normalized = [];
        $total = 0.0;

        foreach ($rows as $row) {
            $splitAmount = $this->money($row['amount'] ?? 0);
            if ($splitAmount <= self::EPSILON) {
                throw new DomainException('Each split Payment amount must be greater than zero.');
            }

            $method = $this->paymentMethod(
                (int) $actor->business_id,
                (int) ($row['payment_method_reference_id'] ?? 0),
            );

            $normalized[] = [
                'method' => $method,
                'amount' => $splitAmount,
                'reference_no' => $this->nullableTrim($row['reference_no'] ?? null),
            ];
            $total = round($total + $splitAmount, 2);
        }

        $this->assertMoneyEquals(
            $total,
            $expectedTotal,
            $tipAmount > self::EPSILON
                ? 'Split Payment total must equal Checkout Payment plus the electronic tip.'
                : 'Split Payment total must equal the total Checkout Payment.',
        );

        $tipSplit = null;
        if ($tipAmount > self::EPSILON) {
            $tipMapping = $this->tipPaymentMapping((int) $actor->business_id, $tipMethodId);
            $candidateIndex = null;
            foreach ($normalized as $index => $row) {
                if ((int) $row['method']->id === $tipMethodId && (float) $row['amount'] + self::EPSILON >= $tipAmount) {
                    $candidateIndex = $index;
                    break;
                }
            }

            if ($candidateIndex === null) {
                throw new DomainException('The selected bank/e-wallet tip method must be included in the Payment Method rows with enough amount to cover the tip.');
            }

            $selected = $normalized[$candidateIndex];
            $tipSplit = [
                'method' => $selected['method'],
                'mapping' => $tipMapping,
                'amount' => $tipAmount,
                'reference_no' => $selected['reference_no'],
            ];
            $normalized[$candidateIndex]['amount'] = round((float) $selected['amount'] - $tipAmount, 2);
            $normalized = array_values(array_filter($normalized, fn (array $row) => (float) $row['amount'] > self::EPSILON));
        }

        $serviceTotal = round(array_sum(array_map(fn (array $row) => (float) $row['amount'], $normalized)), 2);
        $this->assertMoneyEquals(
            $serviceTotal,
            $amountNow,
            'Payment amount allocated to the sale must equal the Checkout Payment after separating the electronic tip.',
        );

        return ['checkout' => $normalized, 'tip' => $tipSplit];
    }

    private function tipPaymentMapping(int $businessId, int $methodId): AccountingPaymentMethodMapping
    {
        if ($methodId <= 0) throw new DomainException('Select which bank/e-wallet Payment Method received the tip.');

        $mapping = AccountingPaymentMethodMapping::query()
            ->where('business_id', $businessId)
            ->where('payment_method_reference_id', $methodId)
            ->where('customer_facing', true)
            ->with('cashAccount')
            ->first();

        $account = $mapping?->cashAccount;
        if (! $mapping || ! $account || ! $account->active || strtoupper((string) $account->posting_type) !== 'POSTING' || ! $account->is_cash_account) {
            throw new DomainException('The selected tip Payment Method is not mapped to an active customer collection account.');
        }

        if (in_array(strtoupper((string) $account->system_key), ['CASH_ON_HAND', 'PETTY_CASH', 'CASH_WITH_MANAGER'], true)) {
            throw new DomainException('Only tips received through a business bank, e-wallet, or card/POS clearing account are tracked. Cash tips are not recorded in YODAMEN.');
        }

        return $mapping;
    }

    private function createStaffTip(
        User $actor,
        Sale $sale,
        Payment $payment,
        Personnel $personnel,
        AccountingPaymentMethodMapping $mapping,
        float $amount,
    ): StaffTip {
        $now = $this->clock->now();
        $tip = StaffTip::query()->create([
            'business_id' => $sale->business_id,
            'branch_id' => $sale->branch_id,
            'code' => $this->codes->next('STAFF_TIP:'.$now->format('my'), 'TIP-'.$now->format('my').'-', 6, $sale->business_id),
            'sale_id' => $sale->id,
            'booking_id' => $sale->booking_id,
            'customer_id' => $sale->customer_id,
            'personnel_id' => $personnel->id,
            'payment_id' => $payment->id,
            'payment_method_reference_id' => $payment->payment_method_reference_id,
            'received_cash_account_id' => $mapping->cash_account_id,
            'amount' => $amount,
            'received_method_snapshot' => $payment->payment_method_snapshot,
            'received_reference' => $payment->reference_no,
            'status' => 'PENDING',
            'created_by' => $actor->id,
        ]);

        PaymentApplication::query()->create([
            'code' => $this->codes->next('PAYMENT_APPLICATION:'.$now->format('my'), 'PAP-'.$now->format('my').'-', 7, $sale->business_id),
            'application_key' => $payment->id.':STAFF_TIP:'.$tip->id,
            'payment_id' => $payment->id,
            'sale_id' => $sale->id,
            'target_type' => 'STAFF_TIP',
            'target_sale_item_id' => null,
            'amount' => $amount,
            'allocation_order' => 9000,
            'status' => 'ACTIVE',
            'target_snapshot' => [
                'source' => 'ELECTRONIC_STAFF_TIP',
                'staff_tip_id' => $tip->id,
                'staff_tip_code' => $tip->code,
                'personnel_id' => $personnel->id,
                'personnel_name' => $personnel->full_name,
            ],
            'created_by' => $actor->id,
        ]);

        return $tip;
    }

    private function checkoutPaymentSources(Collection $payments): Collection
    {
        return $payments->map(function (Payment $payment) {
            $used = (float) PaymentApplication::query()
                ->where('payment_id', $payment->id)
                ->where('status', 'ACTIVE')
                ->sum('amount');

            return [
                'payment' => $payment,
                'available' => max(0, round((float) $payment->amount - $used, 2)),
            ];
        })->values();
    }

    private function paymentMethod(int $businessId, int $methodId): ReferenceValue
    {
        if ($methodId <= 0) throw new DomainException('Payment Method is required.');
        $method = ReferenceValue::query()
            ->whereKey($methodId)
            ->where('active', true)
            ->whereHas('group', fn ($q) => $q->where('business_id', $businessId)->where('code', 'PAYMENT_METHOD')->where('active', true))
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

    /**
     * Finalization is the inventory issue point for retail items attached to a Booking/Visit.
     * A PRODUCT line must never be financially finalized without either an existing RETAIL_SALE
     * movement or a resolvable Product Master record. This prevents a successful paid checkout
     * from silently skipping stock deduction.
     *
     * @return array{posted_count:int, already_posted_count:int, rows:array<int,array<string,mixed>>}
     */
    private function postBookingRetailInventory(User $actor, Sale $sale): array
    {
        // Always use a fresh item set here. reconcileSale() has already mutated the sale/items and
        // we do not want an earlier loaded relationship to hide a missing product linkage.
        $sale = Sale::query()->whereKey($sale->id)->firstOrFail();
        $items = SaleItem::query()
            ->where('sale_id', $sale->id)
            ->with(['retailProduct', 'visitRetailLine.retailProduct'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $postedCount = 0;
        $alreadyPostedCount = 0;
        $rows = [];

        foreach ($items as $item) {
            if (strtoupper((string) $item->item_type) !== 'PRODUCT') continue;
            if (in_array(strtoupper((string) ($item->correction_status ?? 'ACTIVE')), ['REMOVED', 'REVERSED'], true)) continue;

            $existingMovement = InventoryMovement::query()
                ->where('sale_item_id', $item->id)
                ->where('movement_type', 'RETAIL_SALE')
                ->first();
            if ($existingMovement) {
                $alreadyPostedCount++;
                $rows[] = [
                    'sale_item_id' => $item->id,
                    'retail_product_id' => $existingMovement->retail_product_id,
                    'movement_id' => $existingMovement->id,
                    'quantity_change' => (float) $existingMovement->quantity_change,
                    'status' => 'ALREADY_POSTED',
                ];
                continue;
            }

            $product = $this->resolveRetailProductForSaleItem($sale, $item);

            $stock = InventoryStock::query()->firstOrCreate(
                ['branch_id' => $sale->branch_id, 'retail_product_id' => $product->id],
                ['business_id' => $sale->business_id, 'on_hand' => 0, 'reserved' => 0],
            );
            $stock = InventoryStock::query()->whereKey($stock->id)->lockForUpdate()->firstOrFail();

            $factor = max(0.000001, (float) ($item->selling_to_basic_factor_snapshot ?: $product->selling_to_basic_factor ?: 1));
            $sellingQty = max(0, (float) $item->quantity);
            $basicQty = round((float) ($item->basic_quantity_snapshot ?: ($sellingQty * $factor)), 4);
            if ($basicQty <= 0.00005) {
                throw new DomainException($product->name.' has an invalid inventory quantity on this transaction. Checkout was not finalized.');
            }

            $before = round((float) $stock->on_hand, 4);
            $available = round($before - (float) $stock->reserved, 4);
            if ($available + 0.00005 < $basicQty) {
                throw new DomainException($product->name.' no longer has enough available stock to finalize this Booking purchase.');
            }

            $after = round($before - $basicQty, 4);
            $stock->update(['on_hand' => $after]);

            $weightedBasicCost = (float) $product->unit_cost;
            $item->update([
                'retail_product_id' => $product->id,
                'sku_snapshot' => $item->sku_snapshot ?: $product->sku,
                'selling_uom_snapshot' => $item->selling_uom_snapshot ?: ($product->selling_uom ?: $product->unit),
                'selling_to_basic_factor_snapshot' => $factor,
                'basic_quantity_snapshot' => $basicQty,
                'unit_cost_snapshot' => round($weightedBasicCost * $factor, 2),
                'updated_by' => $actor->id,
            ]);

            $movement = InventoryMovement::query()->create([
                'business_id' => $sale->business_id,
                'branch_id' => $sale->branch_id,
                'retail_product_id' => $product->id,
                'sale_id' => $sale->id,
                'sale_item_id' => $item->id,
                'movement_type' => 'RETAIL_SALE',
                'quantity_change' => -$basicQty,
                'quantity_before' => $before,
                'quantity_after' => $after,
                'unit_cost_snapshot' => $weightedBasicCost,
                'reference_code' => $sale->code,
                'reason' => 'Booking checkout finalized retail purchase',
                'created_by' => $actor->id,
                'movement_at' => $this->clock->now(),
            ]);

            $postedCount++;
            $rows[] = [
                'sale_item_id' => $item->id,
                'retail_product_id' => $product->id,
                'movement_id' => $movement->id,
                'quantity_change' => -$basicQty,
                'quantity_before' => $before,
                'quantity_after' => $after,
                'status' => 'POSTED',
            ];
        }

        return [
            'posted_count' => $postedCount,
            'already_posted_count' => $alreadyPostedCount,
            'rows' => $rows,
        ];
    }

    private function resolveRetailProductForSaleItem(Sale $sale, SaleItem $item): RetailProduct
    {
        if ($item->retail_product_id) {
            $product = RetailProduct::query()
                ->where('business_id', $sale->business_id)
                ->whereKey($item->retail_product_id)
                ->first();
            if ($product) return $product;
        }

        $visitRetailLine = $item->visitRetailLine;
        if ($visitRetailLine?->retail_product_id) {
            $product = RetailProduct::query()
                ->where('business_id', $sale->business_id)
                ->whereKey($visitRetailLine->retail_product_id)
                ->first();
            if ($product) {
                $item->update([
                    'retail_product_id' => $product->id,
                    'sku_snapshot' => $item->sku_snapshot ?: ($visitRetailLine->sku_snapshot ?: $product->sku),
                ]);
                return $product;
            }
        }

        $sku = trim((string) ($item->sku_snapshot ?: $visitRetailLine?->sku_snapshot));
        if ($sku !== '') {
            $product = RetailProduct::query()
                ->where('business_id', $sale->business_id)
                ->where('sku', $sku)
                ->first();
            if ($product) {
                $item->update(['retail_product_id' => $product->id]);
                return $product;
            }
        }

        throw new DomainException(
            $item->item_name_snapshot.' is a retail sale line without a Product Master link. '
            .'Checkout was not finalized so inventory is not silently skipped. Correct the transaction and select the Product Master item first.'
        );
    }

    private function paymentMethods(int $businessId): array
    {
        $mappings = AccountingPaymentMethodMapping::query()
            ->where('business_id', $businessId)
            ->where('customer_facing', true)
            ->with('cashAccount')
            ->get()
            ->keyBy('payment_method_reference_id');

        return ReferenceValue::query()
            ->where('active', true)
            ->whereHas('group', fn ($q) => $q->where('business_id', $businessId)->where('code', 'PAYMENT_METHOD')->where('active', true))
            ->orderBy('sort_order')->orderBy('display_value')
            ->get(['id', 'code', 'display_value'])
            ->map(function ($method) use ($mappings) {
                $mapping = $mappings->get($method->id);
                $account = $mapping?->cashAccount;
                $supportsTip = $account
                    && $account->active
                    && strtoupper((string) $account->posting_type) === 'POSTING'
                    && $account->is_cash_account
                    && ! in_array(strtoupper((string) $account->system_key), ['CASH_ON_HAND', 'PETTY_CASH', 'CASH_WITH_MANAGER'], true);

                return [
                    'id' => $method->id,
                    'code' => $method->code,
                    'name' => $method->display_value,
                    'supports_tip' => (bool) $supportsTip,
                ];
            })
            ->values()->all();
    }

    private function saleSnapshot(Sale $sale): array
    {
        return [
            'id' => $sale->id,
            'code' => $sale->code,
            'status' => $sale->status,
            'gross_amount' => (float) $sale->gross_amount,
            'discount_amount' => (float) ($sale->discount_amount ?? 0),
            'net_amount' => (float) ($sale->net_amount ?? $sale->gross_amount),
            'discount_code' => $sale->discount_code_snapshot,
            'total_paid' => (float) $sale->total_paid,
            'applied_amount' => (float) $sale->applied_amount,
            'outstanding_balance' => (float) $sale->outstanding_balance,
            'financial_finalized_at' => $sale->financial_finalized_at?->toIso8601String(),
        ];
    }

    private function loadSale(Sale $sale): Sale
    {
        return $sale->load(['customer', 'booking', 'visit', 'items.assignedPersonnel', 'payments.paymentMethod', 'paymentApplications']);
    }

    private function nextPaymentCode(int $businessId): string
    {
        $now = $this->clock->now();
        return $this->codes->next('PAYMENT:'.$now->format('my'), 'PAY-'.$now->format('my').'-', 6, $businessId);
    }

    private function assertOwned(User $actor, Sale $sale): void
    {
        if ((int) $sale->business_id !== (int) $actor->business_id) abort(404);
    }

    private function sumAllocations(array $rows): float
    {
        return round(array_sum(array_map(fn ($row) => (float) $row['amount'], $rows)), 2);
    }

    private function assertMoneyEquals(float $actual, float $expected, string $message): void
    {
        if (abs($actual - $expected) > self::EPSILON) throw new DomainException($message);
    }

    private function money(mixed $value): float
    {
        return round((float) $value, 2);
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }
}
