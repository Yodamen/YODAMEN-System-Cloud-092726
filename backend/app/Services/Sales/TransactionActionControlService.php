<?php

namespace App\Services\Sales;

use App\Models\Personnel;
use App\Models\RetailProduct;
use App\Models\ReferenceValue;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\TransactionActionRequest;
use App\Models\User;
use App\Services\Foundation\CodeSequenceService;
use App\Services\MasterData\WorkflowReasonCatalog;
use App\Services\MasterData\WorkflowReasonService;
use App\Services\Packages\PackageServiceCreditService;
use App\Services\Security\AuditService;
use DomainException;
use Illuminate\Support\Facades\DB;

class TransactionActionControlService
{
    private const ACTIVE_STATUSES = ['PENDING_MANAGER_APPROVAL', 'APPROVED_FOR_POSTING'];

    public function __construct(
        private readonly CodeSequenceService $codes,
        private readonly AuditService $audit,
        private readonly TransactionActionPostingService $posting,
        private readonly PackageServiceCreditService $packages,
        private readonly WorkflowReasonService $workflowReasons,
    ) {
    }

    public function context(User $actor, Sale $sale): array
    {
        $this->assertSaleAccess($actor, $sale);
        $sale->load(['items.assignedPersonnel', 'customer', 'booking', 'visit']);

        return [
            'sale' => $this->saleSnapshot($sale),
            'reasons' => $this->reasonOptions($actor),
            'personnel' => Personnel::query()
                ->where('business_id', $actor->business_id)
                ->where('status', 'ACTIVE')
                ->where('can_perform_services', true)
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get()
                ->map(fn (Personnel $personnel) => [
                    'id' => $personnel->id,
                    'name' => $personnel->full_name,
                    'code' => $personnel->code,
                ])->values(),
            'retail_personnel' => Personnel::query()
                ->where('business_id', $actor->business_id)
                ->where('status', 'ACTIVE')
                ->where('commission_eligible', true)
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get()
                ->map(fn (Personnel $personnel) => [
                    'id' => $personnel->id,
                    'name' => $personnel->full_name,
                    'code' => $personnel->code,
                ])->values(),
            'services' => $this->correctionServiceOptions($actor, $sale),
            'retail_products' => $this->retailProductOptions($actor, $sale),
            'payment_methods' => ReferenceValue::query()
                ->join('reference_groups as rg', 'rg.id', '=', 'reference_values.reference_group_id')
                ->join('accounting_payment_method_mappings as apm', function ($join) use ($actor) {
                    $join->on('apm.payment_method_reference_id', '=', 'reference_values.id')
                        ->where('apm.business_id', '=', $actor->business_id);
                })
                ->join('chart_of_accounts as ca', function ($join) use ($actor) {
                    $join->on('ca.id', '=', 'apm.cash_account_id')
                        ->where('ca.business_id', '=', $actor->business_id)
                        ->where('ca.active', '=', true)
                        ->where('ca.posting_type', '=', 'POSTING')
                        ->where('ca.is_cash_account', '=', true);
                })
                ->where('rg.business_id', $actor->business_id)
                ->where('rg.code', 'PAYMENT_METHOD')
                ->where('apm.customer_facing', true)
                ->where('reference_values.active', true)
                ->orderBy('reference_values.sort_order')
                ->orderBy('reference_values.display_value')
                ->get(['reference_values.id', 'reference_values.code', 'reference_values.display_value', 'ca.id as cash_account_id', 'ca.code as cash_account_code', 'ca.name as cash_account_name'])
                ->map(fn ($method) => [
                    'id' => $method->id,
                    'code' => $method->code,
                    'name' => $method->display_value,
                    'cash_account_id' => $method->cash_account_id,
                    'cash_account_code' => $method->cash_account_code,
                    'cash_account_name' => $method->cash_account_name,
                ])->values(),
            'service_credits' => $sale->customer_id
                ? ($this->packages->availableCreditsForCustomer(
                    $actor,
                    (int) $sale->customer_id,
                    $sale->booking_id ? (int) $sale->booking_id : null,
                    $sale->sale_date?->format('Y-m-d') ?: now()->toDateString(),
                )['service_credits'] ?? [])
                : [],
            'active_request' => $this->activeRequest($sale)?->load(['lines.saleItem', 'requester', 'reviewer']),
        ];
    }

    public function create(User $actor, Sale $sale, array $payload): TransactionActionRequest
    {
        $this->assertSaleAccess($actor, $sale);
        $type = strtoupper(trim((string) ($payload['action_type'] ?? '')));
        if (! in_array($type, ['CORRECTION', 'REFUND', 'REVERSAL'], true)) throw new DomainException('Unsupported transaction action.');

        $permissionAction = match ($type) {
            'CORRECTION' => 'CORRECT',
            'REFUND' => 'REFUND',
            'REVERSAL' => 'REVERSE',
        };
        if (! $actor->hasPermission('sales', $permissionAction)) abort(403, 'You do not have permission to request this transaction action.');

        if ($type === 'CORRECTION' && strtoupper((string) $sale->status) === 'REVERSED') {
            throw new DomainException('A reversed transaction can no longer be corrected.');
        }
        if ($type === 'REVERSAL' && strtoupper((string) $sale->status) === 'REVERSED') {
            throw new DomainException('This transaction has already been reversed.');
        }

        $reasonCode = strtoupper(trim((string) ($payload['reason_code'] ?? '')));
        $reasonReference = $this->workflowReasons->resolveByCode($actor, $reasonCode, $this->reasonContext($type));
        $reasonLabel = (string) $reasonReference->display_value;
        $remarks = trim((string) ($payload['remarks'] ?? ''));
        if ($remarks === '') throw new DomainException('Remarks are required.');

        $lines = collect($payload['lines'] ?? []);
        if ($type === 'CORRECTION' && $lines->isEmpty()) {
            throw new DomainException('Select at least one line to correct.');
        }
        if ($type === 'REVERSAL' && $lines->isNotEmpty()) {
            throw new DomainException('Reversal applies to the entire transaction and cannot be submitted per line.');
        }

        return DB::transaction(function () use ($actor, $sale, $payload, $type, $reasonCode, $reasonLabel, $reasonReference, $remarks, $lines) {
            $lockedSale = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if ($this->activeRequest($lockedSale)) {
                throw new DomainException('This transaction already has a pending or approved action request. Resolve it before creating another request.');
            }

            $lockedSale->load(['items.assignedPersonnel', 'customer', 'booking', 'visit']);
            $snapshot = $this->saleSnapshot($lockedSale);
            $versionHash = $this->saleVersionHash($lockedSale);
            $now = now();

            $requestedRefund = 0.0;
            $impactLines = [];
            $preparedLines = [];

            foreach ($lines as $linePayload) {
                $lineAction = strtoupper(trim((string) ($linePayload['line_action'] ?? 'EDIT')));

                if ($type === 'CORRECTION' && $lineAction === 'ADD') {
                    if (! $lockedSale->visit_id) {
                        throw new DomainException('A new line can only be added through Correction when the transaction is linked to a Visit.');
                    }

                    $itemKind = strtoupper(trim((string) ($linePayload['item_kind'] ?? 'SERVICE')));
                    if ($itemKind === 'SERVICE_CREDIT' || ! empty($linePayload['service_credit_id'])) {
                        $serviceId = (int) ($linePayload['service_id'] ?? 0);
                        $service = Service::query()
                            ->with(['statusReference', 'serviceCount'])
                            ->where('business_id', $actor->business_id)
                            ->whereKey($serviceId)
                            ->first();
                        if (! $service || strtoupper((string) $service->statusReference?->code) !== 'ACTIVE') {
                            throw new DomainException('Select an active service to add under Service Credit.');
                        }

                        $resolved = $this->packages->resolveCreditForBookingLine(
                            $actor,
                            (int) $lockedSale->customer_id,
                            (int) ($linePayload['service_credit_id'] ?? 0),
                            $lockedSale->booking_id ? (int) $lockedSale->booking_id : null,
                            $lockedSale->sale_date?->format('Y-m-d') ?: now()->toDateString(),
                        );
                        $credit = $resolved['credit'];
                        if ((int) $credit->service_id !== (int) $service->id) {
                            throw new DomainException('The selected Service Credit does not match the selected service.');
                        }

                        $personnelId = ! empty($linePayload['assigned_personnel_id']) ? (int) $linePayload['assigned_personnel_id'] : null;
                        $personnel = $personnelId
                            ? Personnel::query()->where('business_id', $actor->business_id)->whereKey($personnelId)->where('status', 'ACTIVE')->first()
                            : null;
                        if ($personnelId && ! $personnel) throw new DomainException('Selected staff is not available.');

                        $proposed = [
                            'item_kind' => 'SERVICE_CREDIT',
                            'service_id' => $service->id,
                            'service_name' => $service->name,
                            'service_credit_id' => $credit->id,
                            'package_enrollment_id' => $credit->package_enrollment_id,
                            'quantity' => 1,
                            'assigned_personnel_id' => $personnelId,
                            'assigned_personnel_name' => $personnel?->full_name,
                            'unit_price' => 0,
                        ];
                        $preparedLines[] = [
                            'sale_item_id' => null,
                            'line_action' => 'ADD',
                            'original_snapshot' => [],
                            'proposed_snapshot' => $proposed,
                            'refund_quantity' => 0,
                            'refund_amount' => 0,
                            'return_to_stock' => null,
                        ];
                        $impactLines[] = ['sale_item_id' => null, 'item' => $service->name, 'change' => 'ADD_SERVICE_CREDIT', 'proposed' => $proposed];
                        continue;
                    }

                    if ($itemKind === 'PRODUCT') {
                        $product = RetailProduct::query()
                            ->where('business_id', $actor->business_id)
                            ->whereKey((int) ($linePayload['retail_product_id'] ?? 0))
                            ->where('status', 'ACTIVE')
                            ->where('stock_type', 'INVENTORY')
                            ->where('retail_available', true)
                            ->first();
                        if (! $product) {
                            throw new DomainException('Select a valid retail product to add.');
                        }

                        $quantity = max(1, (int) ($linePayload['quantity'] ?? 1));
                        $personnelId = ! empty($linePayload['assigned_personnel_id']) ? (int) $linePayload['assigned_personnel_id'] : null;
                        $personnel = $personnelId
                            ? Personnel::query()->where('business_id', $actor->business_id)->whereKey($personnelId)->where('status', 'ACTIVE')->first()
                            : null;
                        if ($personnelId && ! $personnel) throw new DomainException('Selected staff is not available.');
                        $unitPrice = max(0, round((float) ($linePayload['unit_price'] ?? $product->sellingPriceForDate()), 2));

                        $proposed = [
                            'item_kind' => 'PRODUCT',
                            'retail_product_id' => $product->id,
                            'product_name' => $product->name,
                            'sku' => $product->sku,
                            'quantity' => $quantity,
                            'assigned_personnel_id' => $personnelId,
                            'assigned_personnel_name' => $personnel?->full_name,
                            'unit_price' => $unitPrice,
                        ];
                        $preparedLines[] = [
                            'sale_item_id' => null,
                            'line_action' => 'ADD',
                            'original_snapshot' => [],
                            'proposed_snapshot' => $proposed,
                            'refund_quantity' => 0,
                            'refund_amount' => 0,
                            'return_to_stock' => null,
                        ];
                        $impactLines[] = ['sale_item_id' => null, 'item' => $product->name, 'change' => 'ADD_PRODUCT', 'proposed' => $proposed];
                        continue;
                    }

                    $serviceId = (int) ($linePayload['service_id'] ?? 0);
                    $service = Service::query()
                        ->with(['statusReference', 'serviceCount'])
                        ->where('business_id', $actor->business_id)
                        ->whereKey($serviceId)
                        ->first();
                    if (! $service || strtoupper((string) $service->statusReference?->code) !== 'ACTIVE') {
                        throw new DomainException('Select an active service to add.');
                    }
                    if (strtoupper((string) $service->serviceCount?->code) === 'MULTI_SESSION' || (int) ($service->number_of_sessions ?? 1) > 1) {
                        throw new DomainException('Package services must use the package/service-credit workflow and cannot be added as a regular Correction line.');
                    }
                    $quantity = max(1, (int) ($linePayload['quantity'] ?? 1));
                    $personnelId = ! empty($linePayload['assigned_personnel_id']) ? (int) $linePayload['assigned_personnel_id'] : null;
                    $personnel = $personnelId
                        ? Personnel::query()->where('business_id', $actor->business_id)->whereKey($personnelId)->where('status', 'ACTIVE')->first()
                        : null;
                    if ($personnelId && ! $personnel) throw new DomainException('Selected staff is not available.');
                    $unitPrice = max(0, round((float) ($linePayload['unit_price'] ?? 0), 2));
                    $proposed = [
                        'item_kind' => 'SERVICE',
                        'service_id' => $service->id,
                        'service_name' => $service->name,
                        'quantity' => $quantity,
                        'assigned_personnel_id' => $personnelId,
                        'assigned_personnel_name' => $personnel?->full_name,
                        'unit_price' => $unitPrice,
                    ];
                    $preparedLines[] = [
                        'sale_item_id' => null,
                        'line_action' => 'ADD',
                        'original_snapshot' => [],
                        'proposed_snapshot' => $proposed,
                        'refund_quantity' => 0,
                        'refund_amount' => 0,
                        'return_to_stock' => null,
                    ];
                    $impactLines[] = ['sale_item_id' => null, 'item' => $service->name, 'change' => 'ADD', 'proposed' => $proposed];
                    continue;
                }

                $itemId = (int) ($linePayload['sale_item_id'] ?? 0);
                /** @var SaleItem|null $item */
                $item = $lockedSale->items->firstWhere('id', $itemId);
                if (! $item) throw new DomainException('One of the selected lines does not belong to this transaction.');

                $lineCorrectionStatus = strtoupper((string) ($item->correction_status ?? 'ACTIVE'));
                if ($type === 'CORRECTION' && in_array($lineCorrectionStatus, ['REMOVED', 'REVERSED'], true)) {
                    throw new DomainException('A removed or reversed line cannot be corrected again.');
                }

                $original = $this->itemSnapshot($item);
                if ($type === 'REFUND') {
                    // Refunds are whole transaction-line refunds. The server derives the
                    // refundable amount from the line's current effective NET value, so
                    // discounts/promotions can never be refunded back to the customer.
                    $correctionRefundDue = max(0, round((float) ($item->refund_due_amount ?? 0), 2));
                    $remainingEconomicValue = max(0, round((float) ($item->effective_net_line_total ?? $item->net_line_total ?? $item->line_total), 2));
                    $isTerminalCorrection = in_array($lineCorrectionStatus, ['REMOVED', 'REVERSED'], true);
                    if ($isTerminalCorrection && $correctionRefundDue <= 0.009) {
                        throw new DomainException('A removed or reversed line has no remaining Refund Due.');
                    }
                    $refundQty = $isTerminalCorrection ? 0.0 : round((float) $item->quantity, 4);
                    $refundAmount = round($correctionRefundDue + ($isTerminalCorrection ? 0 : $remainingEconomicValue), 2);
                    if ($refundAmount <= 0.009) {
                        throw new DomainException('The selected line has no remaining refundable value.');
                    }
                    $economicRequested = max(0, round($refundAmount - $correctionRefundDue, 2));
                    if ($economicRequested > 0.009
                        && strtoupper((string) $item->item_type) === 'PRODUCT'
                        && (bool) ($linePayload['return_to_stock'] ?? false)) {
                        $remainingReturnableQty = max(0, round((float) $item->quantity - (float) ($item->returned_quantity ?? 0), 4));
                        if ($refundQty > $remainingReturnableQty + 0.00005) {
                            throw new DomainException('The full product line cannot be returned to stock because part of its quantity was already returned.');
                        }
                    }
                    $requestedRefund = round($requestedRefund + $refundAmount, 2);
                    $preparedLines[] = [
                        'sale_item_id' => $item->id,
                        'line_action' => 'REFUND',
                        'original_snapshot' => $original,
                        'proposed_snapshot' => null,
                        'refund_quantity' => $refundQty,
                        'refund_amount' => $refundAmount,
                        'return_to_stock' => strtoupper((string) $item->item_type) === 'PRODUCT' ? (bool) ($linePayload['return_to_stock'] ?? false) : null,
                    ];
                    $impactLines[] = ['sale_item_id' => $item->id, 'item' => $item->item_name_snapshot, 'change' => 'REFUND', 'amount' => $refundAmount];
                    continue;
                }

                if (! in_array($lineAction, ['EDIT', 'REMOVE'], true)) throw new DomainException('Correction line action is invalid.');
                $proposed = null;
                if ($lineAction === 'EDIT') {
                    $quantity = max(1, (int) ($linePayload['quantity'] ?? $item->quantity));
                    $itemType = strtoupper((string) $item->item_type);
                    if (in_array($itemType, ['PACKAGE', 'SERVICE_CREDIT_USE'], true) && $quantity !== (int) $item->quantity) {
                        throw new DomainException($item->item_name_snapshot.' quantity cannot be changed through Correction. Remove/reverse the line and use the appropriate package/service-credit workflow instead.');
                    }
                    $personnelId = array_key_exists('assigned_personnel_id', $linePayload)
                        ? ($linePayload['assigned_personnel_id'] ? (int) $linePayload['assigned_personnel_id'] : null)
                        : $item->assigned_personnel_id;
                    if ($personnelId) {
                        $personnel = Personnel::query()->where('business_id', $actor->business_id)->whereKey($personnelId)->where('status', 'ACTIVE')->first();
                        if (! $personnel) throw new DomainException('Selected staff is not available.');
                    }
                    $unitPrice = round((float) ($linePayload['unit_price'] ?? $item->unit_price), 2);
                    if ($itemType === 'SERVICE_CREDIT_USE' && abs($unitPrice - (float) $item->unit_price) > 0.009) {
                        throw new DomainException('Service Credit lines cannot be repriced through Correction.');
                    }
                    $proposed = [
                        'quantity' => $quantity,
                        'assigned_personnel_id' => $personnelId,
                        'unit_price' => $unitPrice,
                    ];
                }
                $preparedLines[] = [
                    'sale_item_id' => $item->id,
                    'line_action' => $lineAction,
                    'original_snapshot' => $original,
                    'proposed_snapshot' => $proposed,
                    'refund_quantity' => 0,
                    'refund_amount' => 0,
                    'return_to_stock' => null,
                ];
                $impactLines[] = ['sale_item_id' => $item->id, 'item' => $item->item_name_snapshot, 'change' => $lineAction, 'proposed' => $proposed];
            }

            if ($type === 'CORRECTION') {
                $removeIds = collect($preparedLines)
                    ->where('line_action', 'REMOVE')
                    ->pluck('sale_item_id')
                    ->map(fn ($id) => (int) $id);
                $remainingActiveLines = $lockedSale->items
                    ->reject(fn (SaleItem $item) => in_array(strtoupper((string) ($item->correction_status ?? 'ACTIVE')), ['REMOVED', 'REVERSED'], true))
                    ->reject(fn (SaleItem $item) => $removeIds->contains((int) $item->id))
                    ->count() + collect($preparedLines)->where('line_action', 'ADD')->count();
                if ($remainingActiveLines === 0) {
                    throw new DomainException('Correction cannot remove every transaction line. Use Reversal for the entire transaction.');
                }
            }

            $request = TransactionActionRequest::query()->create([
                'business_id' => $actor->business_id,
                'branch_id' => $lockedSale->branch_id,
                'sale_id' => $lockedSale->id,
                'code' => $this->codes->nextAvailable(
                    'TRANSACTION_ACTION:'.$now->format('my'),
                    'TAR-'.$now->format('my').'-',
                    fn (string $candidate) => TransactionActionRequest::query()
                        ->where('code', $candidate)
                        ->exists(),
                    6,
                    $actor->business_id,
                ),
                'action_type' => $type,
                'status' => 'PENDING_MANAGER_APPROVAL',
                'reason_code' => $reasonCode,
                'reason_label' => $reasonLabel,
                'reason_reference_id' => $reasonReference->id,
                'remarks' => $remarks,
                'original_snapshot' => $snapshot,
                'proposed_snapshot' => ['lines' => $impactLines],
                'impact_preview' => $this->impactPreview($type, $lockedSale, $impactLines, $requestedRefund),
                'sale_version_hash' => $versionHash,
                'refund_method' => $type === 'REFUND' ? trim((string) ($payload['refund_method'] ?? '')) ?: null : null,
                'refund_reference' => $type === 'REFUND' ? trim((string) ($payload['refund_reference'] ?? '')) ?: null : null,
                'requested_refund_amount' => $requestedRefund,
                'requested_by' => $actor->id,
                'requested_at' => $now,
            ]);

            foreach ($preparedLines as $preparedLine) $request->lines()->create($preparedLine);

            $this->audit->record(
                action: 'REQUEST_TRANSACTION_'.$type,
                module: 'sales',
                entityType: TransactionActionRequest::class,
                entityId: $request->id,
                after: $this->requestSnapshot($request->fresh('lines')),
                reason: $remarks,
                actor: $actor,
                metadata: ['sale_id' => $lockedSale->id, 'sale_code' => $lockedSale->code, 'reason_code' => $reasonCode],
            );

            return $request->fresh(['lines.saleItem', 'requester', 'reviewer', 'reasonReference', 'reviewReasonReference']);
        });
    }

    public function approve(User $actor, TransactionActionRequest $request, ?string $managerRemarks = null): TransactionActionRequest
    {
        if (! $actor->hasPermission('sales', 'APPROVE_ACTION')) abort(403, 'Manager approval permission is required.');
        if ((int) $request->business_id !== (int) $actor->business_id) abort(404);
        if ((int) $request->requested_by === (int) $actor->id && ! $actor->isSuperAdmin()) {
            throw new DomainException('Requester cannot approve their own transaction action request unless they are Super Admin.');
        }

        return DB::transaction(function () use ($actor, $request, $managerRemarks) {
            $locked = TransactionActionRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'PENDING_MANAGER_APPROVAL') throw new DomainException('Only pending requests can be approved.');

            $sale = Sale::query()->whereKey($locked->sale_id)->lockForUpdate()->firstOrFail();
            if (! hash_equals((string) $locked->sale_version_hash, $this->saleVersionHash($sale))) {
                throw new DomainException('This transaction changed after the request was submitted. Reject or withdraw this stale request and submit a new one.');
            }

            $locked->update([
                'status' => 'APPROVED_FOR_POSTING',
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'manager_remarks' => trim((string) $managerRemarks) ?: null,
            ]);

            $approved = $locked->fresh(['lines.saleItem', 'requester', 'reviewer']);
            $this->audit->record(
                action: 'APPROVE_TRANSACTION_ACTION',
                module: 'sales',
                entityType: TransactionActionRequest::class,
                entityId: $approved->id,
                after: $this->requestSnapshot($approved),
                reason: $approved->manager_remarks,
                actor: $actor,
                metadata: ['sale_id' => $approved->sale_id, 'action_type' => $approved->action_type],
            );

            // Approval and posting are one atomic transaction. If any downstream
            // module fails, Laravel rolls the approval back to PENDING_MANAGER_APPROVAL.
            return $this->posting->post($actor, $approved);
        });
    }

    public function reject(User $actor, TransactionActionRequest $request, string $managerRemarks, ?int $reviewReasonReferenceId = null): TransactionActionRequest
    {
        if (! $actor->hasPermission('sales', 'APPROVE_ACTION')) abort(403, 'Manager approval permission is required.');
        if ((int) $request->business_id !== (int) $actor->business_id) abort(404);
        if ((int) $request->requested_by === (int) $actor->id && ! $actor->isSuperAdmin()) {
            throw new DomainException('Requester cannot review their own transaction action request unless they are Super Admin.');
        }
        $managerRemarks = trim($managerRemarks);
        if ($managerRemarks === '') throw new DomainException('Manager remarks are required when rejecting a request.');

        return DB::transaction(function () use ($actor, $request, $managerRemarks, $reviewReasonReferenceId) {
            $locked = TransactionActionRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'PENDING_MANAGER_APPROVAL') throw new DomainException('Only pending requests can be rejected.');
            $locked->update([
                'status' => 'REJECTED',
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'manager_remarks' => $managerRemarks,
                'review_reason_reference_id' => $reviewReasonReferenceId,
            ]);
            $fresh = $locked->fresh(['lines.saleItem', 'requester', 'reviewer', 'reasonReference', 'reviewReasonReference']);
            $this->audit->record(
                action: 'REJECT_TRANSACTION_ACTION',
                module: 'sales',
                entityType: TransactionActionRequest::class,
                entityId: $fresh->id,
                after: $this->requestSnapshot($fresh),
                reason: $managerRemarks,
                actor: $actor,
                metadata: ['sale_id' => $fresh->sale_id, 'action_type' => $fresh->action_type],
            );
            return $fresh;
        });
    }

    public function pendingRequests(User $actor): array
    {
        $query = TransactionActionRequest::query()
            ->with(['sale.customer', 'sale.booking', 'requester', 'lines'])
            ->where('business_id', $actor->business_id)
            ->where('status', 'PENDING_MANAGER_APPROVAL')
            ->latest('requested_at')
            ->latest('id');

        if ($actor->default_branch_id) {
            $query->where(function ($branchQuery) use ($actor) {
                $branchQuery->whereNull('branch_id')
                    ->orWhere('branch_id', $actor->default_branch_id);
            });
        }

        // Approvers see the full branch queue. Request-only users can still
        // trace the requests they personally submitted.
        if (! $actor->hasPermission('sales', 'APPROVE_ACTION')) {
            $query->where('requested_by', $actor->id);
        }

        return $query->get()->map(function (TransactionActionRequest $request) use ($actor) {
            $requesterIsActor = (int) $request->requested_by === (int) $actor->id;
            $superAdminOverride = $requesterIsActor && $actor->isSuperAdmin();
            $canReview = $actor->hasPermission('sales', 'APPROVE_ACTION')
                && (! $requesterIsActor || $superAdminOverride);

            return [
                'id' => $request->id,
                'code' => $request->code,
                'sale_id' => $request->sale_id,
                'sale_code' => $request->sale?->code,
                'booking_code' => $request->sale?->booking?->code,
                'customer_name' => $request->sale?->customer?->full_name,
                'sale_status' => $request->sale?->status,
                'sale_amount' => (float) ($request->sale?->effective_net_amount ?? $request->sale?->net_amount ?? 0),
                'balance_due' => (float) ($request->sale?->outstanding_balance ?? 0),
                'refund_due' => (float) ($request->sale?->refund_due_amount ?? 0),
                'action_type' => $request->action_type,
                'status' => $request->status,
                'reason_label' => $request->reason_label,
                'remarks' => $request->remarks,
                'requested_by' => $request->requester ? [
                    'id' => $request->requester->id,
                    'name' => $request->requester->display_name ?: $request->requester->name,
                ] : null,
                'requested_at' => $request->requested_at?->toIso8601String(),
                'selected_line_count' => $request->lines->count(),
                'requested_refund_amount' => (float) $request->requested_refund_amount,
                'can_review' => $canReview,
                'super_admin_self_approval' => $superAdminOverride,
            ];
        })->values()->all();
    }

    public function activeRequest(Sale $sale): ?TransactionActionRequest
    {
        return TransactionActionRequest::query()
            ->where('sale_id', $sale->id)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->latest('id')
            ->first();
    }

    public function requestResource(TransactionActionRequest $request): array
    {
        $request->loadMissing(['lines.saleItem', 'requester', 'reviewer', 'poster', 'reasonReference', 'reviewReasonReference']);
        return [
            'id' => $request->id,
            'code' => $request->code,
            'sale_id' => $request->sale_id,
            'action_type' => $request->action_type,
            'status' => $request->status,
            'reason_code' => $request->reason_code,
            'reason_label' => $request->reason_label,
            'reason_category' => $this->workflowReasons->serialize($request->reasonReference),
            'remarks' => $request->remarks,
            'refund_method' => $request->refund_method,
            'refund_reference' => $request->refund_reference,
            'requested_refund_amount' => (float) $request->requested_refund_amount,
            'impact_preview' => $request->impact_preview,
            'requested_by' => $request->requester ? ['id' => $request->requester->id, 'name' => $request->requester->display_name ?: $request->requester->name] : null,
            'requested_at' => $request->requested_at?->toIso8601String(),
            'reviewed_by' => $request->reviewer ? ['id' => $request->reviewer->id, 'name' => $request->reviewer->display_name ?: $request->reviewer->name] : null,
            'reviewed_at' => $request->reviewed_at?->toIso8601String(),
            'manager_remarks' => $request->manager_remarks,
            'review_reason_category' => $this->workflowReasons->serialize($request->reviewReasonReference),
            'posting_summary' => $request->posting_summary,
            'posted_by' => $request->poster ? ['id' => $request->poster->id, 'name' => $request->poster->display_name ?: $request->poster->name] : null,
            'posted_at' => $request->posted_at?->toIso8601String(),
            'lines' => $request->lines->map(fn ($line) => [
                'id' => $line->id,
                'sale_item_id' => $line->sale_item_id,
                'item_name' => $line->original_snapshot['item_name'] ?? $line->proposed_snapshot['service_name'] ?? $line->proposed_snapshot['product_name'] ?? $line->saleItem?->item_name_snapshot,
                'line_action' => $line->line_action,
                'original_snapshot' => $line->original_snapshot,
                'proposed_snapshot' => $line->proposed_snapshot,
                'refund_quantity' => (float) $line->refund_quantity,
                'refund_amount' => (float) $line->refund_amount,
                'return_to_stock' => $line->return_to_stock,
            ])->values(),
        ];
    }

    private function correctionServiceOptions(User $actor, Sale $sale): array
    {
        $effectiveDate = $sale->visit?->visit_date?->format('Y-m-d')
            ?: $sale->sale_date?->format('Y-m-d')
            ?: now()->toDateString();
        $branchId = $sale->branch_id;

        return Service::query()
            ->with(['statusReference', 'serviceCount'])
            ->where('business_id', $actor->business_id)
            ->whereHas('statusReference', fn ($query) => $query->where('code', 'ACTIVE'))
            ->orderBy('name')
            ->get()
            ->map(function (Service $service) use ($branchId, $effectiveDate) {
                $price = ServicePrice::query()
                    ->where('service_id', $service->id)
                    ->whereDate('effective_from', '<=', $effectiveDate)
                    ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $effectiveDate))
                    ->where(function ($query) use ($branchId) {
                        $branchId
                            ? $query->where('branch_id', $branchId)->orWhereNull('branch_id')
                            : $query->whereNull('branch_id');
                    })
                    ->orderByRaw('branch_id is null')
                    ->orderByDesc('effective_from')
                    ->first();

                return [
                    'id' => $service->id,
                    'code' => $service->code,
                    'name' => $service->name,
                    'service_count' => $service->serviceCount?->code,
                    'number_of_sessions' => (int) ($service->number_of_sessions ?? 1),
                    'current_price' => $price ? (float) $price->standard_price : 0.0,
                ];
            })
            ->values()
            ->all();
    }

    private function retailProductOptions(User $actor, Sale $sale): array
    {
        return RetailProduct::query()
            ->where('business_id', $actor->business_id)
            ->where('status', 'ACTIVE')
            ->where('stock_type', 'INVENTORY')
            ->where('retail_available', true)
            ->with(['stocks' => fn ($query) => $sale->branch_id ? $query->where('branch_id', $sale->branch_id) : $query])
            ->orderBy('name')
            ->get()
            ->map(function (RetailProduct $product) {
                $stock = $product->stocks->first();
                $available = max(0, round((float) ($stock?->on_hand ?? 0) - (float) ($stock?->reserved ?? 0), 4));

                return [
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'category' => $product->category,
                    'brand' => $product->brand,
                    'unit' => $product->unit,
                    'selling_price' => $product->sellingPriceForDate(),
                    'available' => $available,
                ];
            })->values()->all();
    }

    private function assertSaleAccess(User $actor, Sale $sale): void
    {
        if ((int) $sale->business_id !== (int) $actor->business_id) abort(404);
        if ($actor->default_branch_id && (int) $sale->branch_id !== (int) $actor->default_branch_id) abort(404);
    }

    private function saleVersionHash(Sale $sale): string
    {
        $sale->loadMissing('items');
        return hash('sha256', json_encode([
            'sale_updated_at' => optional($sale->updated_at)->toISOString(),
            'status' => $sale->status,
            'financial_finalized_at' => optional($sale->financial_finalized_at)->toISOString(),
            'gross_amount' => (string) $sale->gross_amount,
            'net_amount' => (string) $sale->net_amount,
            'total_paid' => (string) $sale->total_paid,
            'outstanding_balance' => (string) $sale->outstanding_balance,
            'items' => $sale->items->map(fn (SaleItem $item) => [
                $item->id, optional($item->updated_at)->toISOString(), $item->status, $item->quantity,
                (string) $item->unit_price, (string) $item->net_line_total, (string) ($item->effective_net_line_total ?? ''),
                (string) ($item->refund_due_amount ?? ''), $item->correction_status ?? null, $item->refund_status ?? null, $item->assigned_personnel_id,
            ])->values()->all(),
        ], JSON_THROW_ON_ERROR));
    }

    private function saleSnapshot(Sale $sale): array
    {
        return [
            'id' => $sale->id,
            'code' => $sale->code,
            'status' => $sale->status,
            'financial_finalized' => $sale->financial_finalized_at !== null,
            'booking_code' => $sale->booking?->code,
            'visit_id' => $sale->visit_id,
            'customer_name' => $sale->customer?->full_name,
            'gross_amount' => (float) $sale->gross_amount,
            'net_amount' => (float) ($sale->net_amount ?? $sale->gross_amount),
            'effective_net_amount' => (float) ($sale->effective_net_amount ?? $sale->net_amount ?? $sale->gross_amount),
            'refund_due_amount' => (float) ($sale->refund_due_amount ?? 0),
            'total_paid' => (float) $sale->total_paid,
            'outstanding_balance' => (float) $sale->outstanding_balance,
            'items' => $sale->items->map(fn (SaleItem $item) => $this->itemSnapshot($item))->values()->all(),
        ];
    }

    private function itemSnapshot(SaleItem $item): array
    {
        return [
            'id' => $item->id,
            'code' => $item->code,
            'item_type' => $item->item_type,
            'item_name' => $item->item_name_snapshot,
            'quantity' => (int) $item->quantity,
            'unit_price' => (float) $item->unit_price,
            'line_total' => (float) $item->line_total,
            'discount_amount' => (float) ($item->discount_amount ?? 0),
            'promotion_applications' => $item->promotion_applications ?: [],
            'net_line_total' => (float) ($item->net_line_total ?? $item->line_total),
            'effective_net_line_total' => (float) ($item->effective_net_line_total ?? $item->net_line_total ?? $item->line_total),
            'refund_due_amount' => (float) ($item->refund_due_amount ?? 0),
            'correction_status' => $item->correction_status ?? 'ACTIVE',
            'refund_status' => $item->refund_status ?? 'NONE',
            'assigned_personnel_id' => $item->assigned_personnel_id,
            'assigned_personnel_name' => $item->assigned_personnel_name_snapshot,
            'visit_service_line_id' => $item->visit_service_line_id,
            'visit_retail_line_id' => $item->visit_retail_line_id,
            'service_id' => $item->service_id,
            'retail_product_id' => $item->retail_product_id,
            'package_enrollment_id' => $item->package_enrollment_id,
            'service_credit_id' => $item->service_credit_id,
            'status' => $item->status,
        ];
    }

    private function impactPreview(string $type, Sale $sale, array $lines, float $refundAmount): array
    {
        return [
            'action_type' => $type,
            'sale_code' => $sale->code,
            'financial_finalized' => $sale->financial_finalized_at !== null,
            'selected_lines' => $lines,
            'requested_refund_amount' => $refundAmount,
            'manager_approval_required' => true,
            'maker_checker_enforced' => true,
            'posting_note' => 'Manager approval posts the action atomically. Before Checkout, approved Correction values become the effective transaction basis; after Checkout, Correction creates only Balance Due or Refund Due as applicable.',
        ];
    }

    private function requestSnapshot(TransactionActionRequest $request): array
    {
        return [
            'code' => $request->code,
            'sale_id' => $request->sale_id,
            'action_type' => $request->action_type,
            'status' => $request->status,
            'reason_code' => $request->reason_code,
            'remarks' => $request->remarks,
            'requested_refund_amount' => (float) $request->requested_refund_amount,
            'requested_by' => $request->requested_by,
            'reviewed_by' => $request->reviewed_by,
            'line_count' => $request->lines->count(),
        ];
    }
    private function reasonContext(string $type): string
    {
        return match (strtoupper($type)) {
            'CORRECTION' => WorkflowReasonCatalog::SALES_CORRECTION,
            'REFUND' => WorkflowReasonCatalog::SALES_REFUND,
            'REVERSAL' => WorkflowReasonCatalog::SALES_REVERSAL,
            default => throw new DomainException('Unsupported transaction action.'),
        };
    }

    private function reasonOptions(User $actor): array
    {
        $result = [];
        foreach (['CORRECTION', 'REFUND', 'REVERSAL'] as $type) {
            $result[$type] = collect($this->workflowReasons->options($actor, $this->reasonContext($type)))
                ->mapWithKeys(fn (array $option) => [$option['code'] => $option['name']])
                ->all();
        }
        return $result;
    }

}
