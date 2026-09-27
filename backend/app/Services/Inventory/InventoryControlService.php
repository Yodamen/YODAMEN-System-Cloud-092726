<?php

namespace App\Services\Inventory;

use App\Models\AccountingPaymentMethodMapping;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\InventoryCount;
use App\Models\InventoryCountLine;
use App\Models\InventoryMovement;
use App\Models\InventoryReceipt;
use App\Models\InventoryReceiptLine;
use App\Models\InventoryStock;
use App\Models\InventoryStockReturn;
use App\Models\RetailProduct;
use App\Models\ReferenceGroup;
use App\Models\ReferenceValue;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\AccountingJournalService;
use App\Services\Foundation\BusinessClock;
use App\Services\Foundation\CodeSequenceService;
use App\Services\Security\AuditService;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryControlService
{
    public function __construct(
        private readonly CodeSequenceService $codes,
        private readonly AuditService $audit,
        private readonly BusinessClock $clock,
        private readonly AccountingJournalService $journals,
        private readonly InventoryReceiptActionService $receiptActions,
    ) {
    }

    public function receiptOptions(User $actor): array
    {
        return [
            'branches' => $this->branches($actor),
            'products' => $this->inventoryProducts($actor),
            'uoms' => $this->uomOptions((int) $actor->business_id),
            'receiving_accounts' => $this->receiptActions->refundReceivingAccounts($actor)
                ->map(fn (ChartOfAccount $account) => ['id'=>$account->id,'code'=>$account->code,'name'=>$account->name])
                ->values(),
            'funding_sources' => ChartOfAccount::query()
                ->where('business_id', $actor->business_id)
                ->where('active', true)
                ->where('posting_type', 'POSTING')
                ->where('is_funding_source', true)
                ->orderBy('code')
                ->get(['id','code','name','account_type','is_cash_account'])
                ->map(fn (ChartOfAccount $account) => [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'account_type' => $account->account_type,
                    'is_cash_account' => (bool) $account->is_cash_account,
                ])->values(),
            // Legacy API option retained for older clients. New supplier/expense flows use funding_sources.
            'payment_methods' => ReferenceValue::query()
                ->where('active', true)
                ->whereHas('group', fn ($query) => $query->where('business_id', $actor->business_id)->where('code', 'PAYMENT_METHOD'))
                ->orderBy('sort_order')
                ->orderBy('display_value')
                ->get()
                ->map(function (ReferenceValue $method) use ($actor) {
                    $mapping = AccountingPaymentMethodMapping::query()
                        ->where('business_id', $actor->business_id)
                        ->where('payment_method_reference_id', $method->id)
                        ->with('cashAccount')
                        ->first();
                    $cashAccount = $mapping?->cashAccount;
                    $configured = $cashAccount
                        && $cashAccount->active
                        && strtoupper((string) $cashAccount->posting_type) === 'POSTING'
                        && (bool) $cashAccount->is_cash_account
                        && (bool) $cashAccount->is_funding_source;

                    return [
                        'id' => $method->id,
                        'code' => $method->code,
                        'name' => $method->display_value,
                        'configured' => (bool) $configured,
                        'cash_account_id' => $configured ? $cashAccount->id : null,
                        'cash_account' => $configured ? $cashAccount->code.' · '.$cashAccount->name : null,
                    ];
                })
                ->values(),
            'suppliers' => Supplier::query()->where('business_id', $actor->business_id)->where('status', 'ACTIVE')->with(['items' => fn ($q) => $q->select('retail_products.id')])->orderBy('code_name')->orderBy('company_name')->get()->map(fn (Supplier $supplier) => [
                'id' => $supplier->id, 'code' => $supplier->code, 'company_name' => $supplier->company_name, 'code_name' => $supplier->code_name,
                'item_ids' => $supplier->items->pluck('id')->map(fn ($id) => (int) $id)->values(),
            ])->values(),
        ];
    }

    public function receipts(User $actor, array $filters = []): array
    {
        $query = InventoryReceipt::query()
            ->where('business_id', $actor->business_id)
            ->with(['branch', 'supplier', 'creator', 'lines.product', 'actionRequests.requester', 'actionRequests.reviewer', 'actionRequests.lines.receiptLine.product', 'actionRequests.refunds.receivingAccount', 'actionRequests.refunds.creator'])
            ->orderByDesc('received_date')
            ->orderByDesc('id');

        if (! empty($filters['branch_id'])) {
            $this->branch($actor, (int) $filters['branch_id']);
            $query->where('branch_id', (int) $filters['branch_id']);
        } elseif ($actor->default_branch_id) {
            $query->where('branch_id', $actor->default_branch_id);
        }

        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', (int) $filters['supplier_id']);
        }
        if (! empty($filters['product_id'])) {
            $query->whereHas('lines', fn ($lineQuery) => $lineQuery->where('retail_product_id', (int) $filters['product_id']));
        }
        if (! empty($filters['from'])) $query->whereDate('received_date', '>=', $filters['from']);
        if (! empty($filters['to'])) $query->whereDate('received_date', '<=', $filters['to']);
        if (! empty($filters['search'])) {
            $term = trim((string) $filters['search']);
            $query->where(function ($q) use ($term) {
                $q->where('code', 'like', '%'.$term.'%')
                    ->orWhere('supplier_name', 'like', '%'.$term.'%')
                    ->orWhereHas('supplier', fn ($sq) => $sq->where('code_name', 'like', '%'.$term.'%'))
                    ->orWhere('reference_no', 'like', '%'.$term.'%')
                    ->orWhereHas('lines.product', fn ($pq) => $pq->where('sku', 'like', '%'.$term.'%')->orWhere('name', 'like', '%'.$term.'%'));
            });
        }

        return [
            'options' => $this->receiptOptions($actor),
            'receipts' => $query->limit(300)->get()->map(fn (InventoryReceipt $receipt) => $this->serializeReceipt($receipt))->values(),
        ];
    }

    public function receive(User $actor, array $data): InventoryReceipt
    {
        $branch = $this->branch($actor, (int) ($data['branch_id'] ?? 0));
        $receivedDate = $this->date((string) ($data['received_date'] ?? ''), 'Received date is required.');
        if ($receivedDate > $this->clock->today()) {
            throw new DomainException('Received date cannot be in the future.');
        }

        $lines = collect($data['lines'] ?? []);
        if ($lines->isEmpty()) throw new DomainException('Add at least one inventory item to the receipt.');

        $productIds = $lines->pluck('retail_product_id')->map(fn ($id) => (int) $id)->filter()->values();
        if ($productIds->count() !== $productIds->unique()->count()) {
            throw new DomainException('Each inventory item can appear only once per receipt.');
        }

        $supplier = Supplier::query()->where('business_id', $actor->business_id)->where('status', 'ACTIVE')->find((int) ($data['supplier_id'] ?? 0));
        if (! $supplier) throw new DomainException('Select a valid active supplier.');

        $paymentStatus = strtoupper(trim((string) ($data['payment_status'] ?? 'UNPAID')));
        if (! in_array($paymentStatus, ['PAID', 'UNPAID'], true)) {
            throw new DomainException('Select a valid payment status.');
        }

        $rawSplits = $data['payment_splits'] ?? [];
        if ($paymentStatus === 'UNPAID' && ($rawSplits || ! empty($data['payment_method_reference_id']))) {
            throw new DomainException('Unpaid stock receipts cannot include payment lines. Post the full balance to Accounts Payable, then settle it from Accounts Payable when payment is made.');
        }
        if (! $rawSplits && ! empty($data['payment_method_reference_id'])) {
            $anticipatedGrandTotal = round(
                (float) collect($lines)->sum(fn ($line) => ((float) ($line['purchase_quantity'] ?? $line['quantity_received'] ?? 0)) * ((float) ($line['purchase_unit_cost'] ?? $line['unit_cost'] ?? 0)))
                + (float) ($data['delivery_fee'] ?? 0),
                2
            );
            $rawSplits = [[
                'payment_method_reference_id' => (int) $data['payment_method_reference_id'],
                'amount' => $anticipatedGrandTotal,
                'reference' => $data['payment_reference'] ?? null,
            ]];
        }
        $paymentSplits = $rawSplits ? $this->resolveFundingSplits($actor, $rawSplits) : [];


        return DB::transaction(function () use ($actor, $data, $branch, $receivedDate, $lines, $supplier, $paymentStatus, $paymentSplits) {
            $now = now();
            $code = $this->codes->next(
                'INVENTORY_RECEIPT:'.$actor->business_id.':'.$now->format('my'),
                'IR-'.$now->format('my').'-',
                5,
                $actor->business_id,
            );

            $receipt = InventoryReceipt::query()->create([
                'business_id' => $actor->business_id,
                'code' => $code,
                'branch_id' => $branch->id,
                'received_date' => $receivedDate,
                'supplier_id' => $supplier?->id,
                'supplier_name' => $supplier?->company_name ?: $this->nullable($data['supplier_name'] ?? null),
                'reference_no' => $this->nullable($data['reference_no'] ?? null),
                'notes' => $this->nullable($data['notes'] ?? null),
                'status' => 'POSTED',
                'total_cost' => 0,
                'delivery_fee' => round((float) ($data['delivery_fee'] ?? 0), 2),
                'payment_status' => $paymentStatus,
                'payment_method_reference_id' => $paymentSplits[0]['payment_method_reference_id'] ?? null,
                'payment_method_snapshot' => $paymentSplits[0]['payment_method'] ?? $paymentSplits[0]['funding_source'] ?? null,
                'payment_reference' => $paymentSplits[0]['reference'] ?? null,
                'payment_splits' => $paymentSplits ?: null,
                'created_by' => $actor->id,
                'posted_at' => $now,
            ]);

            $total = 0.0;
            foreach ($lines as $line) {
                $product = RetailProduct::query()
                    ->where('business_id', $actor->business_id)
                    ->where('stock_type', 'INVENTORY')
                    ->where('status', 'ACTIVE')
                    ->find((int) ($line['retail_product_id'] ?? 0));
                if (! $product) throw new DomainException('Select a valid active inventory item.');

                if ($supplier && ! $supplier->items()->whereKey($product->id)->exists()) {
                    throw new DomainException($product->name.' is not assigned to the selected supplier.');
                }

                $purchaseQuantity = round((float) ($line['purchase_quantity'] ?? $line['quantity_received'] ?? 0), 4);
                $purchaseUom = trim((string) ($line['purchase_uom'] ?? $product->purchase_uom ?? $product->unit));
                $factor = round((float) ($line['conversion_factor'] ?? $product->purchase_to_basic_factor ?? 1), 6);
                $purchaseUnitCost = round((float) ($line['purchase_unit_cost'] ?? $line['unit_cost'] ?? 0), 2);
                if ($purchaseQuantity <= 0) throw new DomainException('Purchase quantity must be greater than zero.');
                if ($factor <= 0) throw new DomainException('Conversion factor must be greater than zero.');
                if ($purchaseUnitCost < 0) throw new DomainException('Purchase unit cost cannot be negative.');
                $quantity = round($purchaseQuantity * $factor, 4);
                if ($quantity <= 0) throw new DomainException('Converted Basic UOM quantity must be greater than zero.');
                $unitCost = round($purchaseUnitCost / $factor, 4);

                if (! empty($line['update_product_conversion'])) {
                    $product->update([
                        'purchase_uom' => $purchaseUom ?: $product->unit,
                        'purchase_to_basic_factor' => $factor,
                        'updated_by' => $actor->id,
                    ]);
                }

                $stock = InventoryStock::query()->firstOrCreate(
                    ['branch_id' => $branch->id, 'retail_product_id' => $product->id],
                    ['business_id' => $actor->business_id, 'on_hand' => 0, 'reserved' => 0],
                );
                $stock = InventoryStock::query()->whereKey($stock->id)->lockForUpdate()->firstOrFail();
                $before = round((float) $stock->on_hand, 4);
                $after = round($before + $quantity, 4);

                $oldCost = (float) $product->unit_cost;
                // If stock was zero/negative, there is no positive on-hand cost layer to blend.
                // The receipt establishes the current replacement/WAC basis for the remaining stock.
                // This avoids mathematically distorting WAC by treating a negative quantity as a negative cost layer.
                $weightedCost = $before <= 0
                    ? $unitCost
                    : ($after > 0 ? round((($before * $oldCost) + ($quantity * $unitCost)) / $after, 4) : $unitCost);
                $lineTotal = round($purchaseQuantity * $purchaseUnitCost, 2);
                $total += $lineTotal;

                InventoryReceiptLine::query()->create([
                    'inventory_receipt_id' => $receipt->id,
                    'retail_product_id' => $product->id,
                    'purchase_quantity' => $purchaseQuantity,
                    'purchase_uom' => $purchaseUom ?: $product->unit,
                    'conversion_factor' => $factor,
                    'purchase_unit_cost' => $purchaseUnitCost,
                    'quantity_received' => $quantity,
                    'unit_cost' => $unitCost,
                    'line_total' => $lineTotal,
                ]);

                $stock->update(['on_hand' => $after]);
                $product->update(['unit_cost' => $weightedCost, 'updated_by' => $actor->id]);

                InventoryMovement::query()->create([
                    'business_id' => $actor->business_id,
                    'branch_id' => $branch->id,
                    'retail_product_id' => $product->id,
                    'movement_type' => 'STOCK_RECEIVED',
                    'quantity_change' => $quantity,
                    'quantity_before' => $before,
                    'quantity_after' => $after,
                    'unit_cost_snapshot' => $unitCost,
                    'reference_code' => $receipt->code,
                    'reason' => trim('Stock received'.($receipt->supplier_name ? ' from '.$receipt->supplier_name : '').($receipt->reference_no ? ' · Ref '.$receipt->reference_no : '')),
                    'created_by' => $actor->id,
                    'movement_at' => $now,
                ]);
            }

            $grandTotal = round($total + (float) ($receipt->delivery_fee ?? 0), 2);
            $splitTotal = round((float) collect($paymentSplits)->sum('amount'), 2);
            if ($splitTotal - $grandTotal > 0.01) {
                throw new DomainException('Payment total cannot exceed the stock receipt Grand Total.');
            }
            if ($paymentStatus === 'PAID' && abs($splitTotal - $grandTotal) > 0.01) {
                throw new DomainException('Paid stock receipts must have payments equal to Grand Total. Select Unpaid when a balance will remain in Accounts Payable.');
            }
            if ($paymentStatus === 'UNPAID' && $splitTotal > 0.01) {
                throw new DomainException('Unpaid stock receipts must post the full Grand Total to Accounts Payable.');
            }
            $receipt->update(['total_cost' => round($total, 2), 'original_total_cost' => round($total, 2)]);

            $this->audit->record(
                action: 'RECEIVE_INVENTORY_STOCK', module: 'stock-received', entityType: InventoryReceipt::class,
                entityId: $receipt->id, after: $this->serializeReceipt($receipt->fresh(['branch', 'supplier', 'creator', 'lines.product'])), actor: $actor,
            );

            return $receipt->fresh(['branch', 'supplier', 'creator', 'lines.product']);
        });
    }

    public function countOptions(User $actor): array
    {
        $products = $this->auditCountProducts($actor);
        $categoriesByStockType = collect(['INVENTORY', 'NON_INVENTORY', 'ASSET'])
            ->mapWithKeys(fn (string $type) => [
                $type => $products->where('stock_type', $type)->pluck('category')->filter()->unique()->sort()->values(),
            ]);

        return [
            'branches' => $this->branches($actor),
            'products' => $products,
            'stock_types' => [
                ['code' => 'INVENTORY', 'value' => 'Inventory'],
                ['code' => 'NON_INVENTORY', 'value' => 'Non-inventory'],
                ['code' => 'ASSET', 'value' => 'Asset'],
            ],
            'categories_by_stock_type' => $categoriesByStockType,
            'categories' => $products->pluck('category')->filter()->unique()->sort()->values(),
        ];
    }


    public function markReceiptPaid(User $actor, InventoryReceipt $receipt, array $rawSplits): InventoryReceipt
    {
        if ((int) $receipt->business_id !== (int) $actor->business_id) {
            throw new DomainException('Stock receipt was not found for this business.');
        }
        if (strtoupper((string) $receipt->status) !== 'POSTED') {
            throw new DomainException('Only Posted stock receipts can be paid.');
        }
        if (strtoupper((string) $receipt->payment_status) === 'PAID') {
            throw new DomainException('Stock receipt is already paid.');
        }

        $grandTotal = round((float) $receipt->total_cost + (float) $receipt->delivery_fee, 2);
        if ($grandTotal <= 0.004) throw new DomainException('Stock receipt payable amount must be greater than zero.');

        $existingSplits = collect($receipt->payment_splits ?: []);
        $alreadyPaid = round((float) $existingSplits->sum(fn ($split) => (float) ($split['amount'] ?? 0)), 2);
        $returnCredits = round((float) InventoryStockReturn::query()
            ->where('inventory_receipt_id', $receipt->id)
            ->where('status', 'APPROVED')
            ->sum('ap_credit_amount'), 2);
        $outstanding = round(max(0, $grandTotal - $alreadyPaid - $returnCredits), 2);
        if ($outstanding <= 0.004) throw new DomainException('Stock receipt has no remaining Accounts Payable balance.');

        $paymentSplits = $this->resolveFundingSplits($actor, $rawSplits);
        $splitTotal = round((float) collect($paymentSplits)->sum('amount'), 2);
        if (abs($splitTotal - $outstanding) > 0.01) {
            throw new DomainException('Payment total must equal the remaining supplier payable amount.');
        }

        return DB::transaction(function () use ($actor, $receipt, $outstanding, $paymentSplits, $existingSplits) {
            $journalLines = [[
                'account_key' => 'ACCOUNTS_PAYABLE',
                'debit' => $outstanding,
                'credit' => 0,
                'memo' => 'Settle supplier payable for '.$receipt->code,
            ]];
            foreach ($paymentSplits as $split) {
                $journalLines[] = [
                    'account_id' => $split['funding_account_id'] ?? $split['cash_account_id'],
                    'debit' => 0,
                    'credit' => $split['amount'],
                    'memo' => 'Supplier payment via '.($split['funding_source'] ?? $split['payment_method'] ?? 'Funding Source'),
                ];
            }

            $journal = $this->journals->postEntry(
                (int) $receipt->business_id,
                $receipt->branch_id,
                $this->clock->today(),
                'INVENTORY_RECEIPT',
                (int) $receipt->id,
                'PAYMENT',
                $receipt->code,
                'Supplier payable settled for '.$receipt->code,
                $journalLines,
                $actor->id,
            );
            if (! $journal) throw new DomainException('Unable to post supplier payment journal. Verify the Accounts Payable and Funding Source setup.');

            $mergedSplits = $existingSplits->concat($paymentSplits)->values()->all();
            $first = $mergedSplits[0] ?? null;
            $receipt->update([
                'payment_status' => 'PAID',
                'payment_method_reference_id' => $first['payment_method_reference_id'] ?? null,
                'payment_method_snapshot' => $first['payment_method'] ?? $first['funding_source'] ?? null,
                'payment_reference' => $first['reference'] ?? null,
                'payment_splits' => $mergedSplits,
            ]);

            return $receipt->fresh(['branch','supplier','creator','lines.product']);
        });
    }

    public function counts(User $actor, array $filters = []): array
    {
        $query = InventoryCount::query()
            ->where('business_id', $actor->business_id)
            ->with(['branch', 'creator', 'submitter', 'approver', 'lines.product'])
            ->orderByDesc('count_date')
            ->orderByDesc('id');

        if (! empty($filters['branch_id'])) {
            $this->branch($actor, (int) $filters['branch_id']);
            $query->where('branch_id', (int) $filters['branch_id']);
        } elseif ($actor->default_branch_id) {
            $query->where('branch_id', $actor->default_branch_id);
        }
        if (! empty($filters['status']) && strtoupper((string) $filters['status']) !== 'ALL') {
            $query->where('status', strtoupper((string) $filters['status']));
        }
        if (! empty($filters['from'])) $query->whereDate('count_date', '>=', $filters['from']);
        if (! empty($filters['to'])) $query->whereDate('count_date', '<=', $filters['to']);

        $scopeFilter = strtoupper(trim((string) ($filters['count_scope'] ?? 'ALL')));
        $categoryFilter = trim((string) ($filters['category'] ?? ''));

        $counts = $query->limit(500)->get()
            ->map(fn (InventoryCount $count) => $this->serializeCountSummary($count))
            ->filter(function (array $row) use ($scopeFilter, $categoryFilter) {
                if ($scopeFilter !== '' && $scopeFilter !== 'ALL') {
                    $matchesScope = match ($scopeFilter) {
                        'FULL' => $row['scope_type'] === 'FULL' && ! $row['stock_type'],
                        'FULL_INVENTORY' => $row['scope_type'] === 'STOCK_TYPE' && $row['stock_type'] === 'INVENTORY',
                        'FULL_NON_INVENTORY' => $row['scope_type'] === 'STOCK_TYPE' && $row['stock_type'] === 'NON_INVENTORY',
                        'FULL_ASSET' => $row['scope_type'] === 'STOCK_TYPE' && $row['stock_type'] === 'ASSET',
                        'INVENTORY' => $row['stock_type'] === 'INVENTORY',
                        'NON_INVENTORY' => $row['stock_type'] === 'NON_INVENTORY',
                        'ASSET' => $row['stock_type'] === 'ASSET',
                        default => true,
                    };
                    if (! $matchesScope) return false;
                }

                if ($categoryFilter !== '') {
                    $categories = collect($row['categories'] ?? []);
                    if (! $categories->contains($categoryFilter)) return false;
                }

                return true;
            })
            ->take(200)
            ->values();

        return [
            'options' => $this->countOptions($actor),
            'counts' => $counts,
        ];
    }

    public function startCount(User $actor, array $data): InventoryCount
    {
        $branch = $this->branch($actor, (int) ($data['branch_id'] ?? 0));
        $countDate = $this->date((string) ($data['count_date'] ?? ''), 'Count date is required.');
        if ($countDate > $this->clock->today()) throw new DomainException('Count date cannot be in the future.');

        $requestedScope = strtoupper(trim((string) ($data['scope_type'] ?? 'FULL')));
        $stockType = strtoupper(trim((string) ($data['stock_type'] ?? '')));
        if ($stockType !== '' && ! in_array($stockType, ['INVENTORY', 'NON_INVENTORY', 'ASSET'], true)) {
            throw new DomainException('Select a valid stock type for the audit count.');
        }

        $legacyCategory = $this->nullable($data['category'] ?? null);
        $categories = collect($data['categories'] ?? [])
            ->map(fn ($category) => trim((string) $category))
            ->filter()
            ->unique()
            ->values();
        if ($legacyCategory && $categories->isEmpty()) $categories = collect([$legacyCategory]);

        $selectedIds = collect($data['product_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values();

        // Backward compatibility for pre-91 API clients/tests.
        if ($requestedScope === 'CATEGORY' && ! $legacyCategory) throw new DomainException('Select a category for the audit count.');
        if ($requestedScope === 'SELECTED' && $selectedIds->isEmpty()) throw new DomainException('Select at least one inventory item for the audit count.');
        if (! in_array($requestedScope, ['FULL', 'CATEGORY', 'SELECTED', 'STOCK_TYPE', 'CATEGORIES'], true)) {
            throw new DomainException('Select a valid audit count scope.');
        }

        $baseProducts = RetailProduct::query()
            ->where('business_id', $actor->business_id)
            ->where('status', 'ACTIVE')
            ->when($stockType !== '', fn ($q) => $q->where('stock_type', $stockType))
            ->when($categories->isNotEmpty(), fn ($q) => $q->whereIn('category', $categories->all()))
            ->orderBy('name')
            ->get();

        if ($requestedScope === 'CATEGORY' && $stockType === '') {
            $baseProducts = RetailProduct::query()
                ->where('business_id', $actor->business_id)
                ->where('status', 'ACTIVE')
                ->where('stock_type', 'INVENTORY')
                ->where('category', $legacyCategory)
                ->orderBy('name')
                ->get();
            $stockType = 'INVENTORY';
        }

        if ($requestedScope === 'SELECTED' && $stockType === '' && $categories->isEmpty()) {
            $baseProducts = RetailProduct::query()
                ->where('business_id', $actor->business_id)
                ->where('status', 'ACTIVE')
                ->whereIn('id', $selectedIds->all())
                ->orderBy('name')
                ->get();
            $stockTypes = $baseProducts->pluck('stock_type')->filter()->unique()->values();
            $stockType = $stockTypes->count() === 1 ? (string) $stockTypes->first() : '';
        }

        if ($baseProducts->isEmpty()) throw new DomainException('No active items match the selected audit count scope.');

        $products = $selectedIds->isEmpty()
            ? $baseProducts
            : $baseProducts->whereIn('id', $selectedIds->all())->values();

        if ($products->isEmpty()) throw new DomainException('Select at least one item for the audit count.');
        if ($selectedIds->isNotEmpty() && $products->count() !== $selectedIds->count()) {
            throw new DomainException('One or more selected items are outside the chosen stock type/category scope.');
        }

        $allMatchingSelected = $products->count() === $baseProducts->count();
        if ($requestedScope === 'SELECTED') {
            $storedScope = 'SELECTED';
        } elseif ($requestedScope === 'CATEGORY') {
            $storedScope = 'CATEGORIES';
        } elseif ($requestedScope === 'FULL' && $stockType === '' && $categories->isEmpty() && $allMatchingSelected) {
            $storedScope = 'FULL';
        } elseif ($stockType !== '' && $categories->isEmpty() && $allMatchingSelected) {
            $storedScope = 'STOCK_TYPE';
        } elseif ($categories->isNotEmpty() && $allMatchingSelected) {
            $storedScope = 'CATEGORIES';
        } else {
            $storedScope = 'SELECTED';
        }

        return DB::transaction(function () use ($actor, $data, $branch, $countDate, $storedScope, $stockType, $categories, $products) {
            $now = now();
            $code = $this->codes->next(
                'INVENTORY_COUNT:'.$actor->business_id.':'.$now->format('my'),
                'IC-'.$now->format('my').'-',
                5,
                $actor->business_id,
            );

            $count = InventoryCount::query()->create([
                'business_id' => $actor->business_id,
                'code' => $code,
                'branch_id' => $branch->id,
                'count_date' => $countDate,
                'scope_type' => $storedScope,
                'stock_type' => $stockType !== '' ? $stockType : null,
                'category' => $categories->count() === 1 ? $categories->first() : null,
                'categories_json' => $categories->values()->all(),
                'status' => 'DRAFT',
                'notes' => $this->nullable($data['notes'] ?? null),
                'created_by' => $actor->id,
            ]);

            foreach ($products as $product) {
                $stock = InventoryStock::query()->firstOrCreate(
                    ['branch_id' => $branch->id, 'retail_product_id' => $product->id],
                    ['business_id' => $actor->business_id, 'on_hand' => 0, 'reserved' => 0],
                );
                InventoryCountLine::query()->create([
                    'inventory_count_id' => $count->id,
                    'retail_product_id' => $product->id,
                    'system_quantity_snapshot' => round((float) $stock->on_hand, 4),
                    'actual_quantity' => null,
                    'variance_quantity' => null,
                    'unit_cost_snapshot' => (float) $product->unit_cost,
                ]);
            }

            $this->audit->record(
                action: 'START_INVENTORY_COUNT', module: 'inventory-count', entityType: InventoryCount::class,
                entityId: $count->id,
                after: [
                    'code' => $count->code,
                    'branch_id' => $branch->id,
                    'scope_type' => $storedScope,
                    'stock_type' => $stockType ?: null,
                    'categories' => $categories->values()->all(),
                    'line_count' => $products->count(),
                ],
                actor: $actor,
            );

            return $count->fresh(['branch', 'creator', 'lines.product']);
        });
    }

    public function countDetail(User $actor, InventoryCount $count): array
    {
        $this->assertCountOwned($actor, $count);
        $count->load(['branch', 'creator', 'submitter', 'approver', 'lines.product']);
        return $this->serializeCountDetail($count);
    }

    public function saveCount(User $actor, InventoryCount $count, array $data): InventoryCount
    {
        $this->assertCountOwned($actor, $count);
        if (strtoupper((string) $count->status) !== 'DRAFT') throw new DomainException('Only a Draft audit count can be edited.');

        $rows = collect($data['lines'] ?? []);
        if ($rows->isEmpty()) throw new DomainException('Audit count lines are required.');

        DB::transaction(function () use ($actor, $count, $data, $rows) {
            $locked = InventoryCount::query()->whereKey($count->id)->lockForUpdate()->firstOrFail();
            if (strtoupper((string) $locked->status) !== 'DRAFT') throw new DomainException('Only a Draft audit count can be edited.');

            foreach ($rows as $row) {
                $line = InventoryCountLine::query()->where('inventory_count_id', $locked->id)->find((int) ($row['id'] ?? 0));
                if (! $line) throw new DomainException('Select a valid audit count line.');
                $actual = $row['actual_quantity'] ?? null;
                if ($actual !== null && $actual !== '' && (float) $actual < 0) throw new DomainException('Actual count cannot be negative.');
                $line->update([
                    'actual_quantity' => $actual === null || $actual === '' ? null : round((float) $actual, 4),
                    'line_notes' => $this->nullable($row['line_notes'] ?? null),
                ]);
            }

            if (array_key_exists('notes', $data)) $locked->update(['notes' => $this->nullable($data['notes'])]);

            $this->audit->record(
                action: 'SAVE_INVENTORY_COUNT', module: 'inventory-count', entityType: InventoryCount::class,
                entityId: $locked->id, actor: $actor,
            );
        });

        return $count->fresh(['branch', 'creator', 'lines.product']);
    }

    public function submitCount(User $actor, InventoryCount $count): InventoryCount
    {
        $this->assertCountOwned($actor, $count);

        return DB::transaction(function () use ($actor, $count) {
            $locked = InventoryCount::query()->whereKey($count->id)->lockForUpdate()->firstOrFail();
            if (strtoupper((string) $locked->status) !== 'DRAFT') throw new DomainException('Only a Draft audit count can be submitted.');
            $locked->load('lines');
            if ($locked->lines->contains(fn (InventoryCountLine $line) => $line->actual_quantity === null)) {
                throw new DomainException('Enter the actual quantity for every item before submitting the audit count.');
            }

            foreach ($locked->lines as $line) {
                $line->update(['variance_quantity' => round((float) $line->actual_quantity - (float) $line->system_quantity_snapshot, 4)]);
            }

            $locked->update(['status' => 'SUBMITTED', 'submitted_by' => $actor->id, 'submitted_at' => now()]);
            $this->audit->record(action: 'SUBMIT_INVENTORY_COUNT', module: 'inventory-count', entityType: InventoryCount::class, entityId: $locked->id, actor: $actor);
            return $locked->fresh(['branch', 'creator', 'submitter', 'lines.product']);
        });
    }

    public function approveCount(User $actor, InventoryCount $count): InventoryCount
    {
        $this->assertCountOwned($actor, $count);

        return DB::transaction(function () use ($actor, $count) {
            $locked = InventoryCount::query()->whereKey($count->id)->lockForUpdate()->firstOrFail();
            if (strtoupper((string) $locked->status) !== 'SUBMITTED') throw new DomainException('Only a Submitted audit count can be approved.');
            $locked->load(['lines.product']);

            foreach ($locked->lines as $line) {
                $stock = InventoryStock::query()->firstOrCreate(
                    ['branch_id' => $locked->branch_id, 'retail_product_id' => $line->retail_product_id],
                    ['business_id' => $actor->business_id, 'on_hand' => 0, 'reserved' => 0],
                );
                $stock = InventoryStock::query()->whereKey($stock->id)->lockForUpdate()->firstOrFail();
                $before = round((float) $stock->on_hand, 4);
                $snapshot = round((float) $line->system_quantity_snapshot, 4);
                if (abs($before - $snapshot) > 0.00005) {
                    throw new DomainException('Stock changed after this audit count started. Cancel this count and start a new one before approval.');
                }

                $actual = round((float) $line->actual_quantity, 4);
                $variance = round($actual - $before, 4);
                if (abs($variance) > 0.00005) {
                    $stock->update(['on_hand' => $actual]);
                    InventoryMovement::query()->create([
                        'business_id' => $actor->business_id,
                        'branch_id' => $locked->branch_id,
                        'retail_product_id' => $line->retail_product_id,
                        'movement_type' => 'INVENTORY_COUNT_ADJUSTMENT',
                        'quantity_change' => $variance,
                        'quantity_before' => $before,
                        'quantity_after' => $actual,
                        'unit_cost_snapshot' => (float) $line->unit_cost_snapshot,
                        'reference_code' => $locked->code,
                        'reason' => 'Approved physical audit count variance',
                        'created_by' => $actor->id,
                        'movement_at' => now(),
                    ]);
                }
                if (abs((float) $line->variance_quantity - $variance) > 0.00005) $line->update(['variance_quantity' => $variance]);
            }

            $locked->update(['status' => 'APPROVED', 'approved_by' => $actor->id, 'approved_at' => now()]);
            $this->audit->record(action: 'APPROVE_INVENTORY_COUNT', module: 'inventory-count', entityType: InventoryCount::class, entityId: $locked->id, actor: $actor);
            return $locked->fresh(['branch', 'creator', 'submitter', 'approver', 'lines.product']);
        });
    }

    public function cancelCount(User $actor, InventoryCount $count): InventoryCount
    {
        $this->assertCountOwned($actor, $count);
        if (! in_array(strtoupper((string) $count->status), ['DRAFT', 'SUBMITTED'], true)) {
            throw new DomainException('Only Draft or Submitted audit counts can be cancelled.');
        }
        $count->update(['status' => 'CANCELLED', 'cancelled_at' => now()]);
        $this->audit->record(action: 'CANCEL_INVENTORY_COUNT', module: 'inventory-count', entityType: InventoryCount::class, entityId: $count->id, actor: $actor);
        return $count->fresh(['branch', 'creator', 'submitter', 'approver', 'lines.product']);
    }

    public function serializeReceipt(InventoryReceipt $receipt): array
    {
        $receipt->loadMissing([
            'branch', 'supplier', 'paymentMethod', 'creator', 'lines.product',
            'actionRequests.requester', 'actionRequests.reviewer', 'actionRequests.lines.receiptLine.product',
            'actionRequests.refunds.receivingAccount', 'actionRequests.refunds.creator',
        ]);

        $actions = $receipt->actionRequests->sortByDesc('id')->values();
        $pending = $actions->first(fn ($row) => strtoupper((string) $row->status) === 'PENDING');

        return [
            'id' => $receipt->id,
            'code' => $receipt->code,
            'received_date' => $receipt->received_date?->toDateString(),
            'supplier_id' => $receipt->supplier_id,
            'supplier_name' => $receipt->supplier_name ?: $receipt->supplier?->company_name,
            'supplier_code_name' => $receipt->supplier?->code_name ?: $receipt->supplier_name,
            'reference_no' => $receipt->reference_no,
            'notes' => $receipt->notes,
            'status' => $receipt->status,
            'original_total_cost' => (float) ($receipt->original_total_cost ?? $receipt->total_cost),
            'total_cost' => (float) $receipt->total_cost,
            'delivery_fee' => (float) ($receipt->delivery_fee ?? 0),
            'grand_total' => round((float) $receipt->total_cost + (float) ($receipt->delivery_fee ?? 0), 2),
            'payment_status' => strtoupper((string) ($receipt->payment_status ?: 'UNPAID')),
            'payment_method_reference_id' => $receipt->payment_method_reference_id,
            'payment_method' => $receipt->payment_method_snapshot ?: $receipt->paymentMethod?->display_value,
            'payment_reference' => $receipt->payment_reference,
            'payment_splits' => $receipt->payment_splits ?: [],
            'posted_at' => $receipt->posted_at?->toIso8601String(),
            'branch' => ['id' => $receipt->branch?->id, 'name' => $receipt->branch?->name],
            'created_by' => $receipt->creator?->display_name,
            'pending_action' => $pending ? $this->serializeReceiptAction($pending) : null,
            'action_requests' => $actions->map(fn ($row) => $this->serializeReceiptAction($row))->values(),
            'lines' => $receipt->lines->map(function (InventoryReceiptLine $line) {
                $state = $this->receiptActions->effectiveLineState($line);
                return [
                    'id' => $line->id,
                    'retail_product_id' => $line->retail_product_id,
                    'sku' => $line->product?->sku,
                    'name' => $line->product?->name,
                    'unit' => $line->product?->unit,
                    'basic_uom' => $line->product?->unit,
                    'purchase_uom' => $line->purchase_uom ?: $line->product?->purchase_uom ?: $line->product?->unit,
                    'original_purchase_quantity' => (float) ($line->purchase_quantity ?? $line->quantity_received),
                    'original_purchase_unit_cost' => (float) ($line->purchase_unit_cost ?? $line->unit_cost),
                    'original_quantity_received' => (float) $line->quantity_received,
                    'original_unit_cost' => (float) $line->unit_cost,
                    'original_line_total' => (float) $line->line_total,
                    'purchase_quantity' => (float) $state['purchase_quantity'],
                    'conversion_factor' => (float) ($line->conversion_factor ?: 1),
                    'purchase_unit_cost' => (float) $state['purchase_unit_cost'],
                    'quantity_received' => (float) $state['quantity_received'],
                    'unit_cost' => (float) $state['unit_cost'],
                    'line_total' => (float) $state['line_total'],
                    'has_correction' => abs((float) $state['purchase_quantity'] - (float) ($line->purchase_quantity ?? $line->quantity_received)) > 0.00005
                        || abs((float) $state['purchase_unit_cost'] - (float) ($line->purchase_unit_cost ?? $line->unit_cost)) > 0.00005,
                ];
            })->values(),
        ];
    }

    private function serializeReceiptAction($row): array
    {
        return [
            'id' => (int) $row->id,
            'code' => $row->code,
            'action_type' => strtoupper((string) $row->action_type),
            'status' => strtoupper((string) $row->status),
            'reason' => $row->reason,
            'requested_by' => $row->requester?->display_name,
            'reviewed_by' => $row->reviewer?->display_name,
            'reviewed_at' => $row->reviewed_at?->toIso8601String(),
            'review_notes' => $row->review_notes,
            'created_at' => $row->created_at?->toIso8601String(),
            'supplier_refund_receivable' => (float) ($row->supplier_refund_receivable ?? 0),
            'refund_received' => round((float) $row->refunds->sum('amount'), 2),
            'refund_remaining' => round(max(0, (float) ($row->supplier_refund_receivable ?? 0) - (float) $row->refunds->sum('amount')), 2),
            'refunds' => $row->refunds->map(fn ($refund) => [
                'id'=>(int)$refund->id,
                'refund_date'=>$refund->refund_date?->toDateString(),
                'amount'=>(float)$refund->amount,
                'reference'=>$refund->reference,
                'receiving_account'=>$refund->receivingAccount?->name,
                'received_by'=>$refund->creator?->display_name,
            ])->values(),
            'lines' => $row->lines->map(fn ($line) => [
                'id' => (int) $line->id,
                'inventory_receipt_line_id' => (int) $line->inventory_receipt_line_id,
                'name' => $line->receiptLine?->product?->name,
                'before_purchase_quantity' => (float) $line->before_purchase_quantity,
                'before_purchase_unit_cost' => (float) $line->before_purchase_unit_cost,
                'corrected_purchase_quantity' => (float) $line->corrected_purchase_quantity,
                'corrected_purchase_unit_cost' => (float) $line->corrected_purchase_unit_cost,
                'quantity_delta' => (float) $line->quantity_delta,
                'value_delta' => (float) $line->value_delta,
            ])->values(),
        ];
    }

    private function serializeCountSummary(InventoryCount $count): array
    {
        $count->loadMissing(['branch', 'creator', 'submitter', 'approver', 'lines.product']);
        $stockType = strtoupper((string) ($count->stock_type ?? '')) ?: null;
        $categories = collect($count->categories_json ?? [])
            ->filter()
            ->values();
        if ($categories->isEmpty() && $count->category) $categories = collect([$count->category]);

        $scopeType = strtoupper((string) $count->scope_type);
        $scopeLabel = match ($scopeType) {
            'FULL' => 'Full Count',
            'STOCK_TYPE' => 'Full Count - '.$this->stockTypeLabel($stockType),
            'CATEGORIES' => $this->stockTypeLabel($stockType).' - '.$categories->join(', '),
            'CATEGORY' => 'Inventory - '.($count->category ?: 'Category'),
            'SELECTED' => ($stockType ? $this->stockTypeLabel($stockType).' - ' : '').'Selected Items',
            default => str($scopeType)->replace('_', ' ')->title()->toString(),
        };

        return [
            'id' => $count->id,
            'code' => $count->code,
            'count_date' => $count->count_date?->toDateString(),
            'scope_type' => $scopeType,
            'scope_label' => $scopeLabel,
            'stock_type' => $stockType,
            'stock_type_label' => $stockType ? $this->stockTypeLabel($stockType) : null,
            'category' => $count->category,
            'categories' => $categories->all(),
            'status' => $count->status,
            'notes' => $count->notes,
            'branch' => ['id' => $count->branch?->id, 'name' => $count->branch?->name],
            'created_by' => $count->creator?->display_name,
            'submitted_by' => $count->submitter?->display_name,
            'approved_by' => $count->approver?->display_name,
            'line_count' => $count->lines->count(),
            'variance_lines' => $count->lines->whereNotNull('variance_quantity')->filter(fn ($line) => abs((float) $line->variance_quantity) > 0.00005)->count(),
            'created_at' => $count->created_at?->toIso8601String(),
            'submitted_at' => $count->submitted_at?->toIso8601String(),
            'approved_at' => $count->approved_at?->toIso8601String(),
            'cancelled_at' => $count->cancelled_at?->toIso8601String(),
        ];
    }

    private function serializeCountDetail(InventoryCount $count): array
    {
        $blind = strtoupper((string) $count->status) === 'DRAFT';
        return [
            ...$this->serializeCountSummary($count),
            'blind_count' => $blind,
            'lines' => $count->lines->sortBy(fn ($line) => strtolower((string) $line->product?->name))->map(fn (InventoryCountLine $line) => [
                'id' => $line->id,
                'retail_product_id' => $line->retail_product_id,
                'sku' => $line->product?->sku,
                'name' => $line->product?->name,
                'stock_type' => strtoupper((string) ($line->product?->stock_type ?: 'INVENTORY')),
                'category' => $line->product?->category,
                'unit' => $line->product?->unit,
                'system_quantity' => $blind ? null : (float) $line->system_quantity_snapshot,
                'actual_quantity' => $line->actual_quantity === null ? null : (float) $line->actual_quantity,
                'variance_quantity' => $blind || $line->variance_quantity === null ? null : (float) $line->variance_quantity,
                'unit_cost_snapshot' => $blind ? null : (float) $line->unit_cost_snapshot,
                'line_notes' => $line->line_notes,
            ])->values(),
        ];
    }

    private function auditCountProducts(User $actor): Collection
    {
        return RetailProduct::query()
            ->where('business_id', $actor->business_id)
            ->where('status', 'ACTIVE')
            ->whereIn('stock_type', ['INVENTORY', 'NON_INVENTORY', 'ASSET'])
            ->orderBy('stock_type')
            ->orderBy('category')
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'stock_type', 'category', 'brand', 'unit', 'unit_cost'])
            ->map(fn (RetailProduct $product) => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'stock_type' => strtoupper((string) ($product->stock_type ?: 'INVENTORY')),
                'stock_type_label' => $this->stockTypeLabel(strtoupper((string) ($product->stock_type ?: 'INVENTORY'))),
                'category' => $product->category,
                'brand' => $product->brand,
                'unit' => $product->unit,
                'unit_cost' => (float) $product->unit_cost,
            ])->values();
    }

    private function stockTypeLabel(?string $stockType): string
    {
        return match (strtoupper((string) $stockType)) {
            'NON_INVENTORY' => 'Non-inventory',
            'ASSET' => 'Asset',
            'INVENTORY' => 'Inventory',
            default => 'All Stock Types',
        };
    }

    private function inventoryProducts(User $actor): Collection
    {
        return RetailProduct::query()
            ->where('business_id', $actor->business_id)
            ->where('stock_type', 'INVENTORY')
            ->where('status', 'ACTIVE')
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'category', 'brand', 'unit', 'purchase_uom', 'purchase_to_basic_factor', 'purchase_uom_cost', 'basic_uom_cost', 'unit_cost'])
            ->map(fn (RetailProduct $product) => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'category' => $product->category,
                'brand' => $product->brand,
                'unit' => $product->unit,
                'basic_uom' => $product->unit,
                'purchase_uom' => $product->purchase_uom ?: $product->unit,
                'purchase_to_basic_factor' => (float) ($product->purchase_to_basic_factor ?: 1),
                'purchase_uom_cost' => (float) ($product->purchase_uom_cost ?? ((float) $product->unit_cost * (float) ($product->purchase_to_basic_factor ?: 1))),
                'basic_uom_cost' => (float) ($product->basic_uom_cost ?? $product->unit_cost),
                'unit_cost' => (float) $product->unit_cost,
                'label' => $product->name,
            ])->values();
    }


    private function uomOptions(int $businessId): Collection
    {
        $group = ReferenceGroup::query()->where('business_id', $businessId)->where('code', 'PRODUCT_UOM')->where('active', true)->first();
        if (! $group) return collect();
        return ReferenceValue::query()->where('reference_group_id', $group->id)->where('active', true)
            ->orderBy('sort_order')->orderBy('display_value')->get()
            ->map(fn (ReferenceValue $value) => ['code' => $value->code, 'value' => $value->display_value])->values();
    }

    private function branches(User $actor): Collection
    {
        return Branch::query()->where('business_id', $actor->business_id)->where('status', 'ACTIVE')
            ->when($actor->default_branch_id, fn ($q) => $q->whereKey($actor->default_branch_id))
            ->orderBy('name')->get(['id', 'code', 'name'])->values();
    }

    private function branch(User $actor, int $branchId): Branch
    {
        if ($branchId <= 0) $branchId = (int) $actor->default_branch_id;
        if ($actor->default_branch_id && (int) $actor->default_branch_id !== $branchId) throw new DomainException('Select a branch available to your account.');
        $branch = Branch::query()->where('business_id', $actor->business_id)->where('status', 'ACTIVE')->find($branchId);
        if (! $branch) throw new DomainException('Select a valid active branch.');
        return $branch;
    }

    private function assertCountOwned(User $actor, InventoryCount $count): void
    {
        if ((int) $count->business_id !== (int) $actor->business_id) throw new DomainException('Audit count is not available to this business.');
        if ($actor->default_branch_id && (int) $count->branch_id !== (int) $actor->default_branch_id) throw new DomainException('Audit count is not available to your branch.');
    }

    private function date(string $value, string $message): string
    {
        $value = trim($value);
        if ($value === '') throw new DomainException($message);
        try {
            return \Carbon\CarbonImmutable::parse($value, $this->clock->timezone())->toDateString();
        } catch (\Throwable) {
            throw new DomainException($message);
        }
    }

    private function resolveFundingSplits(User $actor, array $rawSplits): array
    {
        $resolved = [];
        foreach ($rawSplits as $split) {
            $amount = round((float) ($split['amount'] ?? 0), 2);
            if ($amount <= 0) throw new DomainException('Every payment amount must be greater than zero.');

            $fundingAccountId = (int) ($split['funding_account_id'] ?? $split['cash_account_id'] ?? 0);
            if ($fundingAccountId > 0) {
                $account = ChartOfAccount::query()
                    ->where('business_id', $actor->business_id)
                    ->whereKey($fundingAccountId)
                    ->where('active', true)
                    ->where('posting_type', 'POSTING')
                    ->where('is_funding_source', true)
                    ->first();
                if (! $account) throw new DomainException('Select a valid active expense / supplier Funding Source.');

                $resolved[] = [
                    'funding_account_id' => (int) $account->id,
                    'funding_source' => $account->name,
                    'payment_method_reference_id' => null,
                    'payment_method' => $account->name,
                    'cash_account_id' => $account->is_cash_account ? (int) $account->id : null,
                    'cash_account' => $account->is_cash_account ? $account->name : null,
                    'amount' => $amount,
                    'reference' => $this->nullable($split['reference'] ?? null),
                ];
                continue;
            }

            // Legacy clients may still send a Payment Method reference. Resolve it to its mapped cash account.
            $method = ReferenceValue::query()
                ->whereKey((int) ($split['payment_method_reference_id'] ?? 0))
                ->where('active', true)
                ->whereHas('group', fn ($query) => $query->where('business_id', $actor->business_id)->where('code', 'PAYMENT_METHOD'))
                ->first();
            if (! $method) throw new DomainException('Select a valid Funding Source for every payment line.');
            $mapping = AccountingPaymentMethodMapping::query()
                ->where('business_id', $actor->business_id)
                ->where('payment_method_reference_id', $method->id)
                ->with('cashAccount')
                ->first();
            $account = $mapping?->cashAccount;
            if (! $account || ! $account->active || strtoupper((string) $account->posting_type) !== 'POSTING' || ! $account->is_cash_account || ! $account->is_funding_source) {
                throw new DomainException('Map '.$method->display_value.' to an active Expense / Supplier Funding Source under Admin → Accounting Mapping before posting this paid stock receipt.');
            }
            $resolved[] = [
                'funding_account_id' => (int) $account->id,
                'funding_source' => $account->name,
                'payment_method_reference_id' => (int) $method->id,
                'payment_method' => $method->display_value,
                'cash_account_id' => (int) $account->id,
                'cash_account' => $account->name,
                'amount' => $amount,
                'reference' => $this->nullable($split['reference'] ?? null),
            ];
        }

        if (! $resolved) throw new DomainException('Add at least one Funding Source.');
        return $resolved;
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }
}
