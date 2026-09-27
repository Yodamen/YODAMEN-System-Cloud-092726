<?php

namespace App\Services\Sales;

use App\Models\AccountingPaymentMethodMapping;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Payment;
use App\Models\PaymentApplication;
use App\Models\Personnel;
use App\Models\ReferenceValue;
use App\Models\RetailProduct;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\Commissions\CommissionEarningService;
use App\Services\Customers\CustomerClassificationService;
use App\Services\Incentives\IncentiveEarningService;
use App\Services\Discounts\DiscountService;
use App\Services\Foundation\BusinessClock;
use App\Services\Foundation\CodeSequenceService;
use App\Services\Security\AuditService;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RetailPosService
{
    private const EPSILON = 0.009;

    public function __construct(
        private readonly CodeSequenceService $codes,
        private readonly BusinessClock $clock,
        private readonly DiscountService $discounts,
        private readonly CommissionEarningService $commissions,
        private readonly IncentiveEarningService $incentives,
        private readonly AuditService $audit,
        private readonly CustomerClassificationService $customerClassifications,
    ) {
    }

    public function options(User $actor, ?int $branchId = null): array
    {
        $branch = $this->branch($actor, $branchId);

        $products = RetailProduct::query()
            ->where('business_id', $actor->business_id)
            ->where('status', 'ACTIVE')
            ->where('stock_type', 'INVENTORY')
            ->where('retail_available', true)
            ->with(['stocks' => fn ($q) => $q->where('branch_id', $branch->id)])
            ->orderBy('name')
            ->get()
            ->map(function (RetailProduct $product) {
                $stock = $product->stocks->first();
                $onHand = round((float) ($stock?->on_hand ?? 0), 4);
                $reserved = round((float) ($stock?->reserved ?? 0), 4);
                return [
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'barcode' => $product->barcode,
                    'name' => $product->name,
                    'category' => $product->category,
                    'brand' => $product->brand,
                    'unit' => $product->unit,
                    'selling_price' => $product->sellingPriceForDate(),
                    'commission_eligible' => (bool) $product->commission_eligible,
                    'on_hand' => $onHand,
                    'available' => max(0, $onHand - $reserved),
                ];
            })->values();

        return [
            'selected_branch' => ['id' => $branch->id, 'code' => $branch->code, 'name' => $branch->name],
            'branches' => Branch::query()->where('business_id', $actor->business_id)->where('status', 'ACTIVE')
                ->orderBy('name')->get(['id', 'code', 'name'])->values(),
            'products' => $products,
            'customers' => Customer::query()->where('business_id', $actor->business_id)->where('status', 'ACTIVE')
                ->where('is_test_customer', false)->orderBy('last_name')->orderBy('first_name')->limit(500)->get()
                ->map(fn (Customer $c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->full_name, 'mobile_number' => $c->mobile_number])->values(),
            'personnel' => Personnel::query()->where('business_id', $actor->business_id)->where('status', 'ACTIVE')
                ->orderBy('last_name')->orderBy('first_name')->get()
                ->map(fn (Personnel $p) => [
                    'id' => $p->id, 'code' => $p->code, 'name' => $p->full_name,
                    'commission_eligible' => (bool) $p->commission_eligible,
                ])->values(),
            'payment_methods' => $this->paymentMethods((int) $actor->business_id),
        ];
    }

    public function history(User $actor, array $filters = []): Collection
    {
        $query = Sale::query()
            ->where('business_id', $actor->business_id)
            ->where('sale_channel', 'RETAIL_POS')
            ->with(['customer', 'branch', 'items.assignedPersonnel', 'items.retailProduct', 'payments'])
            ->orderByDesc('sale_date')->orderByDesc('id');

        if ($actor->default_branch_id) $query->where('branch_id', $actor->default_branch_id);
        if (! empty($filters['branch_id'])) $query->where('branch_id', (int) $filters['branch_id']);
        if (! empty($filters['status'])) $query->where('status', strtoupper((string) $filters['status']));
        if (! empty($filters['from'])) $query->whereDate('sale_date', '>=', (string) $filters['from']);
        if (! empty($filters['to'])) $query->whereDate('sale_date', '<=', (string) $filters['to']);
        if (! empty($filters['customer_id'])) $query->where('customer_id', (int) $filters['customer_id']);
        if (! empty($filters['personnel_id'])) {
            $query->whereHas('items', fn ($iq) => $iq->where('assigned_personnel_id', (int) $filters['personnel_id']));
        }
        if (! empty($filters['search'])) {
            $term = trim((string) $filters['search']);
            $query->where(function ($q) use ($term) {
                $q->where('code', 'like', '%'.$term.'%')
                    ->orWhereHas('customer', fn ($cq) => $cq->where('first_name', 'like', '%'.$term.'%')->orWhere('last_name', 'like', '%'.$term.'%'))
                    ->orWhereHas('items', fn ($iq) => $iq->where('item_name_snapshot', 'like', '%'.$term.'%')->orWhere('sku_snapshot', 'like', '%'.$term.'%'));
            });
        }

        $sales = $query->limit(300)->get();
        $this->customerClassifications->identitiesForCustomerIds(
            (int) $actor->business_id,
            $sales->pluck('customer_id')->all(),
        );

        return $sales->map(fn (Sale $sale) => $this->serialize($sale));
    }

    public function preview(User $actor, array $data): array
    {
        DB::beginTransaction();

        try {
            $branch = $this->branch($actor, $data['branch_id'] ?? null);
            $customer = null;
            if (! empty($data['customer_id'])) {
                $customer = Customer::query()
                    ->where('business_id', $actor->business_id)
                    ->whereKey((int) $data['customer_id'])
                    ->firstOrFail();
            }

            $normalizedItems = $this->normalizePreviewItems($actor, $branch, $data['items'] ?? []);
            if ($normalizedItems->isEmpty()) {
                throw new DomainException('Add at least one retail product.');
            }

            $sale = $this->createPendingSale(
                actor: $actor,
                branch: $branch,
                customer: $customer,
                normalizedItems: $normalizedItems,
                code: 'POS-PREVIEW-'.Str::uuid(),
                notes: 'Retail POS Preview',
            );

            $discount = $this->discounts->preview($actor, $sale, $data['discount_codes'] ?? ($data['discount_code'] ?? null));

            return [
                'gross_amount' => round((float) $discount['gross_amount'], 2),
                'discount_amount' => round((float) $discount['discount_amount'], 2),
                'net_amount' => round((float) $discount['net_amount'], 2),
                'discount_code' => $discount['discount_code'],
                'discount_codes' => $discount['promotion_codes'] ?? [],
                'items' => collect($discount['items'])->map(function (array $item) use ($sale) {
                    $saleItem = $sale->items->firstWhere('id', $item['sale_item_id']);

                    return [
                        'retail_product_id' => $saleItem?->retail_product_id,
                        'sku' => $saleItem?->sku_snapshot,
                        'name' => $saleItem?->item_name_snapshot,
                        'gross_amount' => round((float) $item['gross_amount'], 2),
                        'discount_amount' => round((float) $item['discount_amount'], 2),
                        'net_amount' => round((float) $item['net_amount'], 2),
                        'rule' => $item['rule'],
                    ];
                })->values(),
            ];
        } finally {
            DB::rollBack();
        }
    }

    public function finalize(User $actor, array $data): Sale
    {
        return DB::transaction(function () use ($actor, $data) {
            $branch = $this->branch($actor, $data['branch_id'] ?? null);
            $customer = null;
            if (! empty($data['customer_id'])) {
                $customer = Customer::query()->where('business_id', $actor->business_id)->whereKey((int) $data['customer_id'])->firstOrFail();
            }

            $normalizedItems = $this->normalizeItems($actor, $branch, $data['items'] ?? []);
            if ($normalizedItems->isEmpty()) throw new DomainException('Add at least one retail product.');

            $now = $this->clock->now();
            $sale = $this->createPendingSale(
                actor: $actor,
                branch: $branch,
                customer: $customer,
                normalizedItems: $normalizedItems,
                code: $this->codes->next(
                    'SALE:'.$now->format('my'),
                    'SAL-'.$now->format('my').'-',
                    6,
                    $actor->business_id,
                ),
                notes: trim((string) ($data['notes'] ?? '')) ?: 'Retail POS',
            );
            $gross = round((float) $sale->gross_amount, 2);

            $discount = $this->discounts->apply($actor, $sale, $data['discount_codes'] ?? ($data['discount_code'] ?? null));
            $netAmount = round((float) $discount['net_amount'], 2);

            $payments = collect($data['payments'] ?? []);
            $paymentTotal = round((float) $payments->sum(fn ($p) => (float) ($p['amount'] ?? 0)), 2);
            if (abs($paymentTotal - $netAmount) > self::EPSILON) {
                throw new DomainException('Split Payment Total must equal the Total Checkout Amount exactly.');
            }
            if ($netAmount > self::EPSILON && $payments->isEmpty()) {
                throw new DomainException('At least one payment method is required.');
            }

            $this->deductInventory($actor, $branch, $sale);
            $sale->refresh()->load('items');
            $createdPayments = $this->createPaymentsAndApplications($actor, $sale, $payments, $netAmount);

            foreach ($sale->items as $item) {
                $net = round((float) ($item->net_line_total ?? $item->line_total), 2);
                $item->update([
                    'applied_amount' => $net,
                    'balance_amount' => 0,
                    'status' => 'PAID',
                    'financial_finalized_at' => $now,
                    'updated_by' => $actor->id,
                ]);
            }

            $sale->update([
                'checkout_payments' => $paymentTotal,
                'total_paid' => $paymentTotal,
                'applied_amount' => $paymentTotal,
                'outstanding_balance' => 0,
                'status' => 'PAID',
                'financial_finalized_at' => $now,
                'updated_by' => $actor->id,
            ]);

            $sale = $sale->fresh(['items.retailProduct', 'items.assignedPersonnel', 'customer', 'branch', 'payments']);
            $earnings = $this->commissions->reconcileSale($actor, $sale);
            $incentives = $this->incentives->reconcileSale($actor, $sale);

            $this->audit->record(
                action: 'FINALIZE_RETAIL_POS_SALE', module: 'retail-pos', entityType: Sale::class, entityId: $sale->id,
                after: $this->serialize($sale), actor: $actor,
                metadata: [
                    'gross_amount' => $gross,
                    'discount_amount' => (float) $discount['discount_amount'],
                    'net_amount' => $netAmount,
                    'payment_count' => $createdPayments->count(),
                    'commission_earnings_count' => $earnings->where('status', '!=', 'REVERSED')->count(),
                    'incentive_earnings_count' => $incentives->count(),
                ],
            );

            return $sale;
        });
    }

    private function createPendingSale(
        User $actor,
        Branch $branch,
        ?Customer $customer,
        Collection $normalizedItems,
        string $code,
        string $notes,
    ): Sale {
        $now = $this->clock->now();
        $sale = Sale::query()->create([
            'business_id' => $actor->business_id,
            'branch_id' => $branch->id,
            'code' => $code,
            'visit_id' => null,
            'booking_id' => null,
            'customer_id' => $customer?->id,
            'sale_channel' => 'RETAIL_POS',
            'sale_date' => $now,
            'gross_amount' => 0,
            'discount_amount' => 0,
            'net_amount' => 0,
            'advance_payments' => 0,
            'checkout_payments' => 0,
            'total_paid' => 0,
            'applied_amount' => 0,
            'outstanding_balance' => 0,
            'status' => 'FOR_CHECKOUT',
            'notes' => $notes,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $gross = 0.0;
        $sort = 10;
        foreach ($normalizedItems as $index => $row) {
            /** @var RetailProduct $product */
            $product = $row['product'];
            /** @var Personnel|null $personnel */
            $personnel = $row['personnel'] ?? null;
            $quantity = $row['quantity'];
            $unitPrice = round($product->sellingPriceForDate(), 2);
            $lineTotal = round($unitPrice * $quantity, 2);
            $gross += $lineTotal;

            $sale->items()->create([
                'code' => $sale->code.'-I'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                'source_key' => 'RETAIL_POS:'.$sale->id.':'.($index + 1),
                'retail_product_id' => $product->id,
                'sku_snapshot' => $product->sku,
                'unit_cost_snapshot' => round((float) $product->unit_cost * (float) ($product->selling_to_basic_factor ?: 1), 2),
                'selling_uom_snapshot' => $product->selling_uom ?: $product->unit,
                'selling_to_basic_factor_snapshot' => (float) ($product->selling_to_basic_factor ?: 1),
                'basic_quantity_snapshot' => $row['basic_quantity'] ?? $this->basicQuantityForSellingUnits($product, $quantity),
                'assigned_personnel_id' => $personnel?->id,
                'assigned_personnel_name_snapshot' => $personnel?->full_name,
                'item_type' => 'PRODUCT',
                'item_name_snapshot' => $product->name,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'discount_amount' => 0,
                'net_line_total' => $lineTotal,
                'effective_net_line_total' => $lineTotal,
                'applied_amount' => 0,
                'balance_amount' => $lineTotal,
                'status' => 'FOR_CHECKOUT',
                'sort_order' => $sort,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $sort += 10;
        }

        $gross = round($gross, 2);
        $sale->update([
            'gross_amount' => $gross,
            'net_amount' => $gross,
            'effective_net_amount' => $gross,
            'outstanding_balance' => $gross,
        ]);

        return $sale->refresh()->load(['items.service', 'items.retailProduct', 'booking', 'customer']);
    }

    public function serialize(Sale $sale): array
    {
        $sale->loadMissing(['customer', 'branch', 'items.retailProduct', 'items.assignedPersonnel', 'payments']);
        $customerIdentity = $sale->customer
            ? $this->customerClassifications->identityForCustomer($sale->customer)
            : null;

        return [
            'id' => $sale->id,
            'code' => $sale->code,
            'sale_date' => $sale->sale_date?->toIso8601String(),
            'status' => $sale->status,
            'customer' => $sale->customer ? [
                'id' => $sale->customer->id,
                'code' => $sale->customer->code,
                'name' => $sale->customer->full_name,
                'segment' => $customerIdentity['customer_type'] ?? $sale->customer->segment_label,
                'customer_type' => $customerIdentity['customer_type'] ?? $sale->customer->segment_label,
                'customer_type_color' => $customerIdentity['customer_type_color'] ?? '#343434',
                'customer_class' => $customerIdentity['customer_class'] ?? 'Unclassified',
            ] : null,
            'branch' => $sale->branch ? ['id' => $sale->branch->id, 'code' => $sale->branch->code, 'name' => $sale->branch->name] : null,
            'gross_amount' => (float) $sale->gross_amount,
            'discount_amount' => (float) ($sale->discount_amount ?? 0),
            'net_amount' => (float) ($sale->net_amount ?? $sale->gross_amount),
            'discount_code' => $sale->discount_code_snapshot,
            'promotion_codes' => $sale->promotion_codes_snapshot ?: ($sale->discount_code_snapshot ? [$sale->discount_code_snapshot] : []),
            'payments' => $sale->payments->map(fn (Payment $p) => [
                'id' => $p->id, 'code' => $p->code, 'amount' => (float) $p->amount,
                'method' => $p->payment_method_snapshot, 'reference_no' => $p->reference_no,
            ])->values(),
            'items' => $sale->items->map(fn (SaleItem $item) => [
                'id' => $item->id,
                'retail_product_id' => $item->retail_product_id,
                'sku' => $item->sku_snapshot,
                'name' => $item->item_name_snapshot,
                'quantity' => (int) $item->quantity,
                'selling_uom' => $item->selling_uom_snapshot ?: ($item->retailProduct?->selling_uom ?: $item->retailProduct?->unit),
                'basic_quantity' => (float) ($item->basic_quantity_snapshot ?? $item->quantity),
                'unit_price' => (float) $item->unit_price,
                'gross_amount' => (float) $item->line_total,
                'discount_amount' => (float) ($item->discount_amount ?? 0),
                'promotion_applications' => $item->promotion_applications ?: [],
                'net_amount' => (float) ($item->net_line_total ?? $item->line_total),
                'assigned_staff' => $item->assignedPersonnel ? [
                    'id' => $item->assignedPersonnel->id,
                    'name' => $item->assigned_personnel_name_snapshot ?: $item->assignedPersonnel->full_name,
                ] : null,
            ])->values(),
        ];
    }

    private function normalizePreviewItems(User $actor, Branch $branch, array $items): Collection
    {
        return collect($items)->map(function (array $row) use ($actor, $branch) {
            $product = RetailProduct::query()
                ->where('business_id', $actor->business_id)
                ->where('status', 'ACTIVE')
                ->where('stock_type', 'INVENTORY')
                ->where('retail_available', true)
                ->whereKey((int) ($row['retail_product_id'] ?? 0))
                ->firstOrFail();

            $quantity = max(1, (int) ($row['quantity'] ?? 1));
            $basicQuantity = $this->basicQuantityForSellingUnits($product, $quantity);
            $stock = InventoryStock::query()->firstOrCreate(
                ['branch_id' => $branch->id, 'retail_product_id' => $product->id],
                ['business_id' => $actor->business_id, 'on_hand' => 0, 'reserved' => 0],
            );
            if (($stock->on_hand - $stock->reserved) < $basicQuantity) {
                throw new DomainException($product->name.' has insufficient stock for this sale.');
            }

            $personnel = null;
            if (! empty($row['assigned_personnel_id'])) {
                $personnel = Personnel::query()
                    ->where('business_id', $actor->business_id)
                    ->where('status', 'ACTIVE')
                    ->whereKey((int) $row['assigned_personnel_id'])
                    ->firstOrFail();
            }

            return ['product' => $product, 'personnel' => $personnel, 'quantity' => $quantity, 'basic_quantity' => $basicQuantity];
        });
    }

    private function normalizeItems(User $actor, Branch $branch, array $items): Collection
    {
        return collect($items)->map(function (array $row) use ($actor, $branch) {
            $product = RetailProduct::query()
                ->where('business_id', $actor->business_id)
                ->where('status', 'ACTIVE')
                ->where('stock_type', 'INVENTORY')
                ->where('retail_available', true)
                ->whereKey((int) ($row['retail_product_id'] ?? 0))
                ->firstOrFail();
            $personnel = Personnel::query()
                ->where('business_id', $actor->business_id)
                ->where('status', 'ACTIVE')
                ->whereKey((int) ($row['assigned_personnel_id'] ?? 0))
                ->firstOrFail();

            $quantity = max(1, (int) ($row['quantity'] ?? 1));
            $basicQuantity = $this->basicQuantityForSellingUnits($product, $quantity);
            $stock = InventoryStock::query()->firstOrCreate(
                ['branch_id' => $branch->id, 'retail_product_id' => $product->id],
                ['business_id' => $actor->business_id, 'on_hand' => 0, 'reserved' => 0],
            );
            if (($stock->on_hand - $stock->reserved) < $basicQuantity) {
                throw new DomainException($product->name.' has insufficient stock for this sale.');
            }

            return ['product' => $product, 'personnel' => $personnel, 'quantity' => $quantity, 'basic_quantity' => $basicQuantity];
        });
    }

    private function deductInventory(User $actor, Branch $branch, Sale $sale): void
    {
        $sale->loadMissing('items.retailProduct');
        foreach ($sale->items as $item) {
            $product = $item->retailProduct;
            if (! $product) throw new DomainException('Retail product is missing from a POS line.');

            $stock = InventoryStock::query()->firstOrCreate(
                ['branch_id' => $branch->id, 'retail_product_id' => $product->id],
                ['business_id' => $actor->business_id, 'on_hand' => 0, 'reserved' => 0],
            );
            $stock = InventoryStock::query()->whereKey($stock->id)->lockForUpdate()->firstOrFail();
            $before = round((float) $stock->on_hand, 4);
            $sellingQuantity = (int) $item->quantity;
            $basicQuantity = round((float) ($item->basic_quantity_snapshot ?? $this->basicQuantityForSellingUnits($product, $sellingQuantity)), 4);
            if ((round($before - (float) $stock->reserved, 4) + 0.00005) < $basicQuantity) {
                throw new DomainException($product->name.' no longer has enough available stock.');
            }
            $after = $before - $basicQuantity;
            $stock->update(['on_hand' => $after]);

            $currentWeightedCost = (float) RetailProduct::query()->whereKey($product->id)->value('unit_cost');
            $sellingFactor = max(0.000001, (float) ($item->selling_to_basic_factor_snapshot ?: $product->selling_to_basic_factor ?: 1));
            $sellingUnitCost = round($currentWeightedCost * $sellingFactor, 2);
            $item->update(['unit_cost_snapshot' => $sellingUnitCost]);

            InventoryMovement::query()->create([
                'business_id' => $actor->business_id,
                'branch_id' => $branch->id,
                'retail_product_id' => $product->id,
                'sale_id' => $sale->id,
                'sale_item_id' => $item->id,
                'movement_type' => 'RETAIL_SALE',
                'quantity_change' => -$basicQuantity,
                'quantity_before' => $before,
                'quantity_after' => $after,
                'unit_cost_snapshot' => $currentWeightedCost,
                'reference_code' => $sale->code,
                'reason' => 'Retail POS finalized sale',
                'created_by' => $actor->id,
                'movement_at' => $this->clock->now(),
            ]);
        }
    }

    private function basicQuantityForSellingUnits(RetailProduct $product, int $sellingQuantity): float
    {
        $factor = max(0.000001, (float) ($product->selling_to_basic_factor ?: 1));
        return round($sellingQuantity * $factor, 4);
    }

    private function createPaymentsAndApplications(User $actor, Sale $sale, Collection $paymentRows, float $netAmount): Collection
    {
        if ($netAmount <= self::EPSILON) return collect();

        $sale->loadMissing('items');
        $itemBalances = $sale->items->mapWithKeys(fn (SaleItem $item) => [
            $item->id => round((float) ($item->net_line_total ?? $item->line_total), 2),
        ])->all();
        $created = collect();
        $allocationOrder = 10;

        foreach ($paymentRows as $index => $row) {
            $amount = round((float) ($row['amount'] ?? 0), 2);
            if ($amount <= self::EPSILON) continue;
            $method = $this->paymentMethod((int) $actor->business_id, (int) ($row['payment_method_reference_id'] ?? 0));
            $payment = Payment::query()->create([
                'business_id' => $actor->business_id,
                'code' => $this->codes->next('PAYMENT:'.$this->clock->now()->format('my'), 'PAY-'.$this->clock->now()->format('my').'-', 6, $actor->business_id),
                'customer_id' => $sale->customer_id,
                'booking_id' => null,
                'sale_id' => $sale->id,
                'visit_id' => null,
                'payment_type' => 'RETAIL_POS',
                'amount' => $amount,
                'payment_method_reference_id' => $method->id,
                'payment_method_snapshot' => $method->display_value,
                'reference_no' => trim((string) ($row['reference_no'] ?? '')) ?: null,
                'payment_date' => $this->clock->now(),
                'status' => 'RECEIVED',
                'idempotency_key' => 'RETAIL_POS_PAYMENT:'.$sale->id.':'.($index + 1),
                'created_by' => $actor->id,
            ]);

            $remaining = $amount;
            foreach ($sale->items as $item) {
                if ($remaining <= self::EPSILON) break;
                $balance = (float) ($itemBalances[$item->id] ?? 0);
                if ($balance <= self::EPSILON) continue;
                $apply = round(min($remaining, $balance), 2);
                $appNow = $this->clock->now();
                PaymentApplication::query()->create([
                    'code' => $this->codes->next('PAYMENT_APPLICATION:'.$appNow->format('my'), 'PAP-'.$appNow->format('my').'-', 7, $actor->business_id),
                    'application_key' => 'RETAIL_POS:'.$payment->id.':'.$item->id,
                    'payment_id' => $payment->id,
                    'sale_id' => $sale->id,
                    'target_type' => 'CHECKOUT_LINE',
                    'target_sale_item_id' => $item->id,
                    'amount' => $apply,
                    'allocation_order' => $allocationOrder,
                    'status' => 'ACTIVE',
                    'target_snapshot' => [
                        'source' => 'RETAIL_POS', 'sale_code' => $sale->code,
                        'sale_item_code' => $item->code, 'sku' => $item->sku_snapshot,
                        'item_name' => $item->item_name_snapshot,
                    ],
                    'created_by' => $actor->id,
                ]);
                $allocationOrder += 10;
                $itemBalances[$item->id] = round($balance - $apply, 2);
                $remaining = round($remaining - $apply, 2);
            }
            if ($remaining > self::EPSILON) throw new DomainException('Payment allocation exceeded the retail sale balance.');
            $created->push($payment);
        }

        if (collect($itemBalances)->contains(fn ($balance) => $balance > self::EPSILON)) {
            throw new DomainException('Retail POS payment allocation did not fully settle every item.');
        }

        return $created;
    }

    private function branch(User $actor, ?int $branchId): Branch
    {
        $id = (int) ($branchId ?: $actor->default_branch_id);
        $branch = Branch::query()->where('business_id', $actor->business_id)->where('status', 'ACTIVE')->whereKey($id)->first();
        if (! $branch) throw new DomainException('Select a valid active branch.');
        return $branch;
    }

    private function paymentMethods(int $businessId): Collection
    {
        $ids = AccountingPaymentMethodMapping::query()
            ->where('business_id', $businessId)
            ->where('customer_facing', true)
            ->whereHas('cashAccount', fn ($q) => $q->where('active', true)->where('posting_type', 'POSTING')->where('is_cash_account', true))
            ->pluck('payment_method_reference_id');

        return ReferenceValue::query()
            ->whereIn('id', $ids)
            ->where('active', true)
            ->whereHas('group', fn ($q) => $q->where('business_id', $businessId)->where('code', 'PAYMENT_METHOD')->where('active', true))
            ->orderBy('sort_order')->orderBy('display_value')->get()
            ->map(fn (ReferenceValue $method) => ['id' => $method->id, 'code' => $method->code, 'name' => $method->display_value])
            ->values();
    }

    private function paymentMethod(int $businessId, int $id): ReferenceValue
    {
        $method = ReferenceValue::query()->whereKey($id)->where('active', true)
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
}
