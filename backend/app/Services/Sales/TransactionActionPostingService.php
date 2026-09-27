<?php

namespace App\Services\Sales;

use App\Models\CommissionEarning;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\PackageEnrollment;
use App\Models\PaymentApplication;
use App\Models\RetailProduct;
use App\Models\Personnel;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Service;
use App\Models\ServiceCompletionEvent;
use App\Models\VisitRetailLine;
use App\Models\VisitServiceLine;
use App\Models\ServiceConsumption;
use App\Models\ServiceCredit;
use App\Models\ServiceCreditLedger;
use App\Models\ServiceCreditUsage;
use App\Models\TransactionActionRequest;
use App\Models\TransactionActionRequestLine;
use App\Models\TransactionRefund;
use App\Models\User;
use App\Services\Commissions\CommissionEarningService;
use App\Services\Foundation\BusinessClock;
use App\Services\Foundation\CodeSequenceService;
use App\Services\Packages\PackageServiceCreditService;
use App\Services\Hr\DailyCompensationService;
use App\Services\Hr\PayrollRecoveryService;
use App\Services\Inventory\ServiceConsumptionPostingService;
use App\Services\Incentives\IncentiveEarningService;
use App\Services\Security\AuditService;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TransactionActionPostingService
{
    private const EPSILON = 0.009;

    public function __construct(
        private readonly CodeSequenceService $codes,
        private readonly BusinessClock $clock,
        private readonly AuditService $audit,
        private readonly CommissionEarningService $commissionEarnings,
        private readonly DailyCompensationService $dailyCompensation,
        private readonly ServiceConsumptionPostingService $serviceConsumption,
        private readonly PackageServiceCreditService $packages,
        private readonly IncentiveEarningService $incentiveEarnings,
        private readonly PayrollRecoveryService $payrollRecoveries,
    ) {
    }

    /**
     * Post an already manager-approved action. The caller may invoke this inside
     * another DB transaction; Laravel's nested transaction handling keeps the
     * approval and every downstream effect atomic.
     */
    public function post(User $actor, TransactionActionRequest $request): TransactionActionRequest
    {
        return DB::transaction(function () use ($actor, $request) {
            $request = TransactionActionRequest::query()
                ->whereKey($request->id)
                ->with('lines')
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $request->business_id !== (int) $actor->business_id) abort(404);
            if ($request->status === 'POSTED') return $request;
            if ($request->status !== 'APPROVED_FOR_POSTING') {
                throw new DomainException('Only an approved transaction action can be posted.');
            }

            $sale = Sale::query()->whereKey($request->sale_id)->lockForUpdate()->firstOrFail();
            $sale->load([
                'items.service', 'items.retailProduct', 'items.assignedPersonnel', 'items.visitServiceLine',
                'items.visitRetailLine', 'items.packageEnrollment.serviceCredit', 'items.serviceCredit',
                'booking', 'customer', 'visit',
            ]);

            if (strtoupper((string) $sale->status) === 'REVERSED') {
                $actionType = strtoupper((string) $request->action_type);
                $reversalRefundDue = max(0, round((float) ($sale->refund_due_amount ?? 0), 2));
                if ($actionType === 'CORRECTION' || ($actionType === 'REFUND' && $reversalRefundDue <= self::EPSILON)) {
                    throw new DomainException('A reversed transaction can no longer be corrected and can only be refunded while a reversal refund is still due.');
                }
            }

            $summary = match (strtoupper((string) $request->action_type)) {
                'CORRECTION' => $this->postCorrection($actor, $sale, $request),
                'REFUND' => $this->postRefund($actor, $sale, $request),
                'REVERSAL' => $this->postReversal($actor, $sale, $request),
                default => throw new DomainException('Unsupported approved transaction action.'),
            };

            $actionType = strtoupper((string) $request->action_type);
            $sale = $this->reconcileSaleFinancialPosition(
                $actor,
                $sale,
                $actionType === 'REVERSAL',
            );

            // A posted correction changes the commercial composition of the sale and
            // must return the surviving lines to Pending for a fresh checkout review.
            // Keep financial_finalized_at intact so the correction journal remains
            // auditable and the accounting event listener can post its adjustment.
            if ($actionType === 'CORRECTION' && strtoupper((string) $sale->status) !== 'REVERSED') {
                $sale->items()->whereNotIn('correction_status', ['REMOVED', 'REVERSED'])->update([
                    'status' => 'FOR_CHECKOUT',
                    'updated_by' => $actor->id,
                ]);
                $sale->update([
                    'status' => 'FOR_CHECKOUT',
                    'updated_by' => $actor->id,
                ]);
                $sale = $sale->fresh('items');
            }

            $summary['commissions'] = $this->reconcileCommissions($actor, $sale, $request);
            $summary['incentives'] = [
                'adjustments_created' => $this->incentiveEarnings->reconcileSale($actor, $sale, $request)->count(),
            ];
            $summary['sale'] = [
                'id' => $sale->id,
                'code' => $sale->code,
                'status' => $sale->status,
                'net_amount' => (float) $sale->net_amount,
                'effective_net_amount' => (float) ($sale->effective_net_amount ?? $sale->net_amount),
                'outstanding_balance' => (float) $sale->outstanding_balance,
                'refund_due_amount' => (float) ($sale->refund_due_amount ?? 0),
                'refund_status' => $sale->refund_status,
            ];

            $request->update([
                'status' => 'POSTED',
                'posting_summary' => $summary,
                'posted_at' => $this->clock->now(),
                'posted_by' => $actor->id,
            ]);

            $fresh = $request->fresh(['lines.saleItem', 'requester', 'reviewer', 'poster']);
            $this->audit->record(
                action: 'POST_TRANSACTION_'.$request->action_type,
                module: 'sales',
                entityType: TransactionActionRequest::class,
                entityId: $request->id,
                after: [
                    'code' => $request->code,
                    'sale_id' => $sale->id,
                    'action_type' => $request->action_type,
                    'status' => 'POSTED',
                    'posting_summary' => $summary,
                ],
                reason: $request->remarks,
                actor: $actor,
                metadata: ['sale_id' => $sale->id, 'sale_code' => $sale->code],
            );

            return $fresh;
        });
    }

    private function postCorrection(User $actor, Sale $sale, TransactionActionRequest $request): array
    {
        $request->loadMissing('lines');
        if ($request->lines->isEmpty()) throw new DomainException('Correction request has no selected lines.');

        $removeIds = $request->lines
            ->where('line_action', 'REMOVE')
            ->pluck('sale_item_id')
            ->map(fn ($id) => (int) $id);
        $remainingCount = $sale->items
            ->filter(fn (SaleItem $item) => ! in_array(strtoupper((string) ($item->correction_status ?? 'ACTIVE')), ['REMOVED', 'REVERSED'], true))
            ->reject(fn (SaleItem $item) => $removeIds->contains((int) $item->id))
            ->count() + $request->lines->where('line_action', 'ADD')->count();
        if ($remainingCount === 0) {
            throw new DomainException('Correction cannot remove every transaction line. Use Reversal for the entire transaction.');
        }

        $financiallyFinalized = $sale->financial_finalized_at !== null;
        $rows = [];

        foreach ($request->lines as $requestLine) {
            if (strtoupper((string) $requestLine->line_action) === 'ADD') {
                $rows[] = $this->postAddedCorrectionLine($actor, $sale, $request, $requestLine, $financiallyFinalized);
                continue;
            }

            /** @var SaleItem $item */
            $item = SaleItem::query()
                ->where('sale_id', $sale->id)
                ->whereKey($requestLine->sale_item_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array(strtoupper((string) ($item->correction_status ?? 'ACTIVE')), ['REMOVED', 'REVERSED'], true)) {
                throw new DomainException('A removed or reversed line cannot be corrected again.');
            }

            $oldQty = (int) $item->quantity;
            $oldUnit = round((float) $item->unit_price, 2);
            $oldBaseNet = round((float) ($item->net_line_total ?? $item->line_total), 2);
            $oldEffective = max(0, round((float) ($item->effective_net_line_total ?? $oldBaseNet), 2));
            $existingRefundAdjustment = max(0, round((float) ($item->refund_adjustment_amount ?? 0), 2));
            $lineAction = strtoupper((string) $requestLine->line_action);

            if ($lineAction === 'REMOVE') {
                $newQty = 0;
                $newUnit = 0.0;
                $newGross = 0.0;
                $newDiscount = 0.0;
                $newBaseNet = 0.0;
                $personnelId = $item->assigned_personnel_id;
                $personnelName = $item->assigned_personnel_name_snapshot;
                $correctionStatus = 'REMOVED';
            } else {
                $proposed = $requestLine->proposed_snapshot ?: [];
                $newQty = max(1, (int) ($proposed['quantity'] ?? $item->quantity));
                if (strtoupper((string) $item->item_type) === 'PRODUCT'
                    && $newQty + 0.00005 < (float) ($item->returned_quantity ?? 0)) {
                    throw new DomainException($item->item_name_snapshot.' quantity cannot be corrected below quantity already returned to stock.');
                }
                $newUnit = max(0, round((float) ($proposed['unit_price'] ?? $item->unit_price), 2));
                $personnelId = array_key_exists('assigned_personnel_id', $proposed)
                    ? ($proposed['assigned_personnel_id'] ? (int) $proposed['assigned_personnel_id'] : null)
                    : $item->assigned_personnel_id;
                $personnel = $personnelId
                    ? Personnel::query()->where('business_id', $sale->business_id)->whereKey($personnelId)->first()
                    : null;
                if ($personnelId && ! $personnel) throw new DomainException('Corrected staff record is unavailable.');
                $personnelName = $personnel?->full_name;
                $newGross = round($newQty * $newUnit, 2);
                $newDiscount = $this->correctedDiscount($item, $newGross, $newQty);
                $newBaseNet = max(0, round($newGross - $newDiscount, 2));
                $correctionStatus = 'CORRECTED';
            }

            if ($existingRefundAdjustment - $newBaseNet > self::EPSILON) {
                throw new DomainException($item->item_name_snapshot.' cannot be corrected below value already economically refunded. Reverse or review the prior refund first.');
            }

            $newEffective = max(0, round($newBaseNet - $existingRefundAdjustment, 2));
            $correctionDelta = round($oldBaseNet - $newBaseNet, 2); // positive = reduction; negative = increase

            $item->update([
                'quantity' => $newQty,
                'basic_quantity_snapshot' => strtoupper((string) $item->item_type) === 'PRODUCT'
                    ? round($newQty * max(0.000001, (float) ($item->selling_to_basic_factor_snapshot ?: 1)), 4)
                    : $item->basic_quantity_snapshot,
                'unit_price' => $newUnit,
                'line_total' => $newGross,
                'discount_amount' => $newDiscount,
                'net_line_total' => $newBaseNet,
                'effective_net_line_total' => $newEffective,
                'assigned_personnel_id' => $personnelId,
                'assigned_personnel_name_snapshot' => $personnelName,
                'correction_adjustment_amount' => round((float) ($item->correction_adjustment_amount ?? 0) + $correctionDelta, 2),
                'correction_status' => $correctionStatus,
                'last_transaction_action_request_id' => $request->id,
                'updated_by' => $actor->id,
            ]);

            $item->refresh();
            $inventory = $this->reconcileOperationalInventoryForCorrection($actor, $sale, $item, $request, $requestLine, $newQty);
            $serviceCredit = $lineAction === 'REMOVE'
                ? $this->reverseServiceCreditEffects($actor, $sale, $item, $request, $requestLine)
                : null;
            $package = $lineAction === 'REMOVE'
                ? $this->cancelOriginPackageIfSafe($actor, $item, $request, false)
                : null;
            $this->syncOperationalSourceLine($actor, $item, $lineAction, $newQty, $personnelId, $personnelName, $request);

            $activeApplied = $financiallyFinalized ? $this->activeAppliedToItem($item->id) : 0.0;
            $refundDue = $financiallyFinalized ? max(0, round($activeApplied - $newEffective, 2)) : 0.0;
            $item->update(['refund_due_amount' => $refundDue]);

            $rows[] = [
                'sale_item_id' => $item->id,
                'item' => $item->item_name_snapshot,
                'line_action' => $lineAction,
                'old_quantity' => $oldQty,
                'new_quantity' => $newQty,
                'old_unit_price' => $oldUnit,
                'new_unit_price' => $newUnit,
                'old_effective_net' => $oldEffective,
                'new_effective_net' => $newEffective,
                'correction_delta' => $correctionDelta,
                'refund_due' => $refundDue,
                'inventory' => $inventory,
                'service_credit' => $serviceCredit,
                'package' => $package,
            ];
        }

        return [
            'type' => 'CORRECTION',
            'financially_finalized' => $financiallyFinalized,
            'lines' => $rows,
        ];
    }

    private function postAddedCorrectionLine(
        User $actor,
        Sale $sale,
        TransactionActionRequest $request,
        TransactionActionRequestLine $requestLine,
        bool $financiallyFinalized,
    ): array {
        $proposed = $requestLine->proposed_snapshot ?: [];
        $itemKind = strtoupper((string) ($proposed['item_kind'] ?? 'SERVICE'));

        return match ($itemKind) {
            'PRODUCT' => $this->postAddedRetailCorrection($actor, $sale, $request, $requestLine, $financiallyFinalized, $proposed),
            'SERVICE_CREDIT' => $this->postAddedServiceCreditCorrection($actor, $sale, $request, $requestLine, $financiallyFinalized, $proposed),
            default => $this->postAddedServiceCorrection($actor, $sale, $request, $requestLine, $financiallyFinalized, $proposed),
        };
    }

    private function postAddedServiceCorrection(
        User $actor,
        Sale $sale,
        TransactionActionRequest $request,
        TransactionActionRequestLine $requestLine,
        bool $financiallyFinalized,
        array $proposed,
    ): array {
        $serviceId = (int) ($proposed['service_id'] ?? 0);
        $quantity = max(1, (int) ($proposed['quantity'] ?? 1));
        $unitPrice = max(0, round((float) ($proposed['unit_price'] ?? 0), 2));
        $personnelId = ! empty($proposed['assigned_personnel_id']) ? (int) $proposed['assigned_personnel_id'] : null;

        $visit = $sale->visit()->lockForUpdate()->first();
        if (! $visit) throw new DomainException('The transaction is not linked to a Visit, so a completed service cannot be added through Correction.');

        $service = Service::query()
            ->with(['statusReference', 'serviceCount'])
            ->where('business_id', $sale->business_id)
            ->whereKey($serviceId)
            ->first();
        if (! $service || strtoupper((string) $service->statusReference?->code) !== 'ACTIVE') {
            throw new DomainException('The service selected for Correction is no longer active.');
        }
        if (strtoupper((string) $service->serviceCount?->code) === 'MULTI_SESSION' || (int) ($service->number_of_sessions ?? 1) > 1) {
            throw new DomainException('Package services must use the package/service-credit workflow and cannot be added as a regular Correction line.');
        }

        $personnel = $personnelId
            ? Personnel::query()->where('business_id', $sale->business_id)->whereKey($personnelId)->where('status', 'ACTIVE')->first()
            : null;
        if ($personnelId && ! $personnel) throw new DomainException('Corrected staff record is unavailable.');
        $personnelName = $personnel?->full_name;

        $now = $this->clock->now();
        $nextSort = ((int) $visit->serviceLines()->max('sort_order')) + 10;
        if ($nextSort <= 0) $nextSort = 10;
        $serviceLine = VisitServiceLine::query()->create([
            'visit_id' => $visit->id,
            'code' => $visit->code.'-S'.str_pad((string) ($visit->serviceLines()->count() + 1), 2, '0', STR_PAD_LEFT),
            'service_id' => $service->id,
            'service_name_snapshot' => $service->name,
            'source_type' => 'NEW_SERVICE',
            'quantity' => $quantity,
            'assigned_personnel_id' => $personnelId,
            'assigned_personnel_name_snapshot' => $personnelName,
            'status' => 'COMPLETED',
            'started_at' => $visit->service_start_at ?: $sale->sale_date ?: $now,
            'completed_at' => $visit->check_out_at ?: $sale->sale_date ?: $now,
            'notes' => 'Added by approved transaction correction '.$request->code,
            'sort_order' => $nextSort,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $completedAt = $serviceLine->completed_at ?: $now;
        $event = ServiceCompletionEvent::query()->create([
            'business_id' => $sale->business_id,
            'code' => $this->codes->next(
                'SERVICE_COMPLETION:'.$completedAt->format('my'),
                'SCE-'.$completedAt->format('my').'-',
                6,
                $sale->business_id,
            ),
            'visit_id' => $visit->id,
            'visit_service_line_id' => $serviceLine->id,
            'booking_id' => $visit->booking_id,
            'customer_id' => $visit->customer_id,
            'service_id' => $service->id,
            'assigned_personnel_id' => $personnelId,
            'service_name_snapshot' => $service->name,
            'service_source' => 'NEW_SERVICE',
            'assigned_personnel_name_snapshot' => $personnelName,
            'quantity' => $quantity,
            'completed_at' => $completedAt,
            'status' => 'ACTIVE',
            'last_transaction_action_request_id' => $request->id,
            'created_by' => $actor->id,
        ]);

        $consumption = $this->serviceConsumption->post($actor, $visit, $serviceLine, $event);

        $lineTotal = round($quantity * $unitPrice, 2);
        $sortOrder = ((int) $sale->items()->max('sort_order')) + 10;
        if ($sortOrder <= 0) $sortOrder = 10;
        $itemSequence = $sale->items()->count() + 1;
        $item = SaleItem::query()->create([
            'sale_id' => $sale->id,
            'code' => $sale->code.'-I'.str_pad((string) $itemSequence, 3, '0', STR_PAD_LEFT),
            'source_key' => 'CORRECTION_ADD_SERVICE:'.$request->id.':'.$requestLine->id,
            'visit_service_line_id' => $serviceLine->id,
            'service_id' => $service->id,
            'assigned_personnel_id' => $personnelId,
            'assigned_personnel_name_snapshot' => $personnelName,
            'item_type' => 'SERVICE',
            'item_name_snapshot' => $service->name,
            'service_source' => 'NEW_SERVICE',
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
            'discount_amount' => 0,
            'net_line_total' => $lineTotal,
            'effective_net_line_total' => $lineTotal,
            'applied_amount' => 0,
            'balance_amount' => $lineTotal,
            'correction_adjustment_amount' => -$lineTotal,
            'correction_status' => 'CORRECTED',
            'refund_status' => 'NONE',
            'refund_due_amount' => 0,
            'status' => 'FOR_CHECKOUT',
            'sort_order' => $sortOrder,
            'last_transaction_action_request_id' => $request->id,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        $requestLine->update(['sale_item_id' => $item->id]);

        $this->audit->record(
            action: 'ADD_SERVICE_BY_TRANSACTION_CORRECTION',
            module: 'servicing',
            entityType: VisitServiceLine::class,
            entityId: $serviceLine->id,
            after: [
                'visit_id' => $visit->id,
                'service_id' => $service->id,
                'service_name' => $service->name,
                'quantity' => $quantity,
                'assigned_personnel_id' => $personnelId,
                'transaction_action_request_id' => $request->id,
            ],
            reason: $request->remarks,
            actor: $actor,
            metadata: ['sale_id' => $sale->id, 'sale_code' => $sale->code, 'request_code' => $request->code],
        );

        return [
            'sale_item_id' => $item->id,
            'item' => $service->name,
            'line_action' => 'ADD',
            'old_quantity' => 0,
            'new_quantity' => $quantity,
            'old_unit_price' => 0,
            'new_unit_price' => $unitPrice,
            'old_effective_net' => 0,
            'new_effective_net' => $lineTotal,
            'correction_delta' => -$lineTotal,
            'refund_due' => 0,
            'balance_due' => $financiallyFinalized ? $lineTotal : 0,
            'inventory' => $consumption ? [
                'service_consumption_id' => $consumption->id,
                'service_consumption_code' => $consumption->code,
                'total_cost' => (float) $consumption->total_cost,
            ] : null,
        ];
    }

    private function postAddedServiceCreditCorrection(
        User $actor,
        Sale $sale,
        TransactionActionRequest $request,
        TransactionActionRequestLine $requestLine,
        bool $financiallyFinalized,
        array $proposed,
    ): array {
        $serviceId = (int) ($proposed['service_id'] ?? 0);
        $serviceCreditId = (int) ($proposed['service_credit_id'] ?? 0);
        $personnelId = ! empty($proposed['assigned_personnel_id']) ? (int) $proposed['assigned_personnel_id'] : null;

        $visit = $sale->visit()->lockForUpdate()->first();
        if (! $visit) throw new DomainException('The transaction is not linked to a Visit, so a Service Credit line cannot be added through Correction.');

        $service = Service::query()
            ->with('statusReference')
            ->where('business_id', $sale->business_id)
            ->whereKey($serviceId)
            ->first();
        if (! $service || strtoupper((string) $service->statusReference?->code) !== 'ACTIVE') {
            throw new DomainException('The service selected for Service Credit correction is no longer active.');
        }

        $resolved = $this->packages->resolveCreditForBookingLine(
            $actor,
            (int) $visit->customer_id,
            $serviceCreditId,
            $visit->booking_id ? (int) $visit->booking_id : null,
            $visit->visit_date?->format('Y-m-d'),
        );
        $credit = $resolved['credit'];
        if ((int) $credit->service_id !== (int) $service->id) {
            throw new DomainException('The selected Service Credit does not match the selected service.');
        }

        $personnel = $personnelId
            ? Personnel::query()->where('business_id', $sale->business_id)->whereKey($personnelId)->where('status', 'ACTIVE')->first()
            : null;
        if ($personnelId && ! $personnel) throw new DomainException('Corrected staff record is unavailable.');
        $personnelName = $personnel?->full_name;

        $now = $this->clock->now();
        $nextSort = ((int) $visit->serviceLines()->max('sort_order')) + 10;
        if ($nextSort <= 0) $nextSort = 10;
        $serviceLine = VisitServiceLine::query()->create([
            'visit_id' => $visit->id,
            'code' => $visit->code.'-S'.str_pad((string) ($visit->serviceLines()->count() + 1), 2, '0', STR_PAD_LEFT),
            'service_id' => $service->id,
            'service_name_snapshot' => $service->name,
            'source_type' => 'SERVICE_CREDIT',
            'source_reference_code' => $credit->code,
            'package_enrollment_id' => $credit->package_enrollment_id,
            'service_credit_id' => $credit->id,
            'quantity' => 1,
            'assigned_personnel_id' => $personnelId,
            'assigned_personnel_name_snapshot' => $personnelName,
            'status' => 'COMPLETED',
            'started_at' => $visit->service_start_at ?: $sale->sale_date ?: $now,
            'completed_at' => $visit->check_out_at ?: $sale->sale_date ?: $now,
            'notes' => 'Added by approved transaction correction '.$request->code,
            'sort_order' => $nextSort,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $completedAt = $serviceLine->completed_at ?: $now;
        $event = ServiceCompletionEvent::query()->create([
            'business_id' => $sale->business_id,
            'code' => $this->codes->next(
                'SERVICE_COMPLETION:'.$completedAt->format('my'),
                'SCE-'.$completedAt->format('my').'-',
                6,
                $sale->business_id,
            ),
            'visit_id' => $visit->id,
            'visit_service_line_id' => $serviceLine->id,
            'booking_id' => $visit->booking_id,
            'customer_id' => $visit->customer_id,
            'service_id' => $service->id,
            'service_credit_id' => $credit->id,
            'assigned_personnel_id' => $personnelId,
            'service_name_snapshot' => $service->name,
            'service_source' => 'SERVICE_CREDIT',
            'assigned_personnel_name_snapshot' => $personnelName,
            'quantity' => 1,
            'completed_at' => $completedAt,
            'status' => 'ACTIVE',
            'last_transaction_action_request_id' => $request->id,
            'created_by' => $actor->id,
        ]);

        $consumption = $this->serviceConsumption->post($actor, $visit, $serviceLine, $event);
        $this->packages->consumeServiceCredit($actor, $serviceLine);

        $sortOrder = ((int) $sale->items()->max('sort_order')) + 10;
        if ($sortOrder <= 0) $sortOrder = 10;
        $itemSequence = $sale->items()->count() + 1;
        $item = SaleItem::query()->create([
            'sale_id' => $sale->id,
            'code' => $sale->code.'-I'.str_pad((string) $itemSequence, 3, '0', STR_PAD_LEFT),
            'source_key' => 'CORRECTION_ADD_SERVICE_CREDIT:'.$request->id.':'.$requestLine->id,
            'visit_service_line_id' => $serviceLine->id,
            'service_id' => $service->id,
            'assigned_personnel_id' => $personnelId,
            'assigned_personnel_name_snapshot' => $personnelName,
            'item_type' => 'SERVICE_CREDIT_USE',
            'item_name_snapshot' => $service->name,
            'service_source' => 'SERVICE_CREDIT',
            'source_reference_code' => $credit->code,
            'package_enrollment_id' => $credit->package_enrollment_id,
            'service_credit_id' => $credit->id,
            'quantity' => 1,
            'unit_price' => 0,
            'line_total' => 0,
            'discount_amount' => 0,
            'net_line_total' => 0,
            'effective_net_line_total' => 0,
            'applied_amount' => 0,
            'balance_amount' => 0,
            'correction_adjustment_amount' => 0,
            'correction_status' => 'CORRECTED',
            'refund_status' => 'NONE',
            'refund_due_amount' => 0,
            'status' => 'SERVICE_CREDIT',
            'sort_order' => $sortOrder,
            'last_transaction_action_request_id' => $request->id,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        $requestLine->update(['sale_item_id' => $item->id]);

        return [
            'sale_item_id' => $item->id,
            'item' => $service->name,
            'line_action' => 'ADD',
            'old_quantity' => 0,
            'new_quantity' => 1,
            'old_unit_price' => 0,
            'new_unit_price' => 0,
            'old_effective_net' => 0,
            'new_effective_net' => 0,
            'correction_delta' => 0,
            'refund_due' => 0,
            'balance_due' => 0,
            'inventory' => $consumption ? [
                'service_consumption_id' => $consumption->id,
                'service_consumption_code' => $consumption->code,
                'total_cost' => (float) $consumption->total_cost,
            ] : null,
            'service_credit' => ['service_credit_id' => $credit->id, 'code' => $credit->code],
        ];
    }

    private function postAddedRetailCorrection(
        User $actor,
        Sale $sale,
        TransactionActionRequest $request,
        TransactionActionRequestLine $requestLine,
        bool $financiallyFinalized,
        array $proposed,
    ): array {
        $productId = (int) ($proposed['retail_product_id'] ?? 0);
        $quantity = max(1, (int) ($proposed['quantity'] ?? 1));
        $unitPrice = max(0, round((float) ($proposed['unit_price'] ?? 0), 2));
        $personnelId = ! empty($proposed['assigned_personnel_id']) ? (int) $proposed['assigned_personnel_id'] : null;

        $visit = $sale->visit()->lockForUpdate()->first();
        if (! $visit) throw new DomainException('The transaction is not linked to a Visit, so a retail line cannot be added through Correction.');

        $product = RetailProduct::query()
            ->where('business_id', $sale->business_id)
            ->whereKey($productId)
            ->where('status', 'ACTIVE')
            ->where('stock_type', 'INVENTORY')
            ->where('retail_available', true)
            ->first();
        if (! $product) throw new DomainException('The retail product selected for Correction is no longer active.');

        $personnel = $personnelId
            ? Personnel::query()->where('business_id', $sale->business_id)->whereKey($personnelId)->where('status', 'ACTIVE')->first()
            : null;
        if ($personnelId && ! $personnel) throw new DomainException('Corrected staff record is unavailable.');
        $personnelName = $personnel?->full_name;

        $nextSort = ((int) $visit->retailLines()->max('sort_order')) + 10;
        if ($nextSort <= 0) $nextSort = 10;
        $retailLine = VisitRetailLine::query()->create([
            'visit_id' => $visit->id,
            'code' => $visit->code.'-R'.str_pad((string) ($visit->retailLines()->count() + 1), 2, '0', STR_PAD_LEFT),
            'retail_product_id' => $product->id,
            'sku_snapshot' => $product->sku,
            'item_name_snapshot' => $product->name,
            'quantity' => $quantity,
            'unit_price_snapshot' => $unitPrice,
            'assigned_personnel_id' => $personnelId,
            'assigned_personnel_name_snapshot' => $personnelName,
            'notes' => 'Added by approved transaction correction '.$request->code,
            'sort_order' => $nextSort,
            'correction_status' => 'CORRECTED',
            'last_transaction_action_request_id' => $request->id,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $lineTotal = round($quantity * $unitPrice, 2);
        $sortOrder = ((int) $sale->items()->max('sort_order')) + 10;
        if ($sortOrder <= 0) $sortOrder = 10;
        $itemSequence = $sale->items()->count() + 1;
        $factor = max(0.000001, (float) ($product->selling_to_basic_factor ?: 1));
        $item = SaleItem::query()->create([
            'sale_id' => $sale->id,
            'code' => $sale->code.'-I'.str_pad((string) $itemSequence, 3, '0', STR_PAD_LEFT),
            'source_key' => 'CORRECTION_ADD_PRODUCT:'.$request->id.':'.$requestLine->id,
            'visit_retail_line_id' => $retailLine->id,
            'retail_product_id' => $product->id,
            'sku_snapshot' => $product->sku,
            'unit_cost_snapshot' => round((float) $product->unit_cost * $factor, 2),
            'selling_uom_snapshot' => $product->selling_uom ?: $product->unit,
            'selling_to_basic_factor_snapshot' => $factor,
            'basic_quantity_snapshot' => round($quantity * $factor, 4),
            'item_type' => 'PRODUCT',
            'assigned_personnel_id' => $personnelId,
            'assigned_personnel_name_snapshot' => $personnelName,
            'item_name_snapshot' => $product->name,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
            'discount_amount' => 0,
            'net_line_total' => $lineTotal,
            'effective_net_line_total' => $lineTotal,
            'applied_amount' => 0,
            'balance_amount' => $lineTotal,
            'correction_adjustment_amount' => -$lineTotal,
            'correction_status' => 'CORRECTED',
            'refund_status' => 'NONE',
            'refund_due_amount' => 0,
            'status' => 'FOR_CHECKOUT',
            'sort_order' => $sortOrder,
            'last_transaction_action_request_id' => $request->id,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        $requestLine->update(['sale_item_id' => $item->id]);

        if ($financiallyFinalized) {
            $this->postRetailSaleInventoryForAddedItem($actor, $sale, $item, $request);
        }

        return [
            'sale_item_id' => $item->id,
            'item' => $product->name,
            'line_action' => 'ADD',
            'old_quantity' => 0,
            'new_quantity' => $quantity,
            'old_unit_price' => 0,
            'new_unit_price' => $unitPrice,
            'old_effective_net' => 0,
            'new_effective_net' => $lineTotal,
            'correction_delta' => -$lineTotal,
            'refund_due' => 0,
            'balance_due' => $financiallyFinalized ? $lineTotal : 0,
            'inventory' => $financiallyFinalized ? ['posted' => true] : null,
        ];
    }

    private function postRetailSaleInventoryForAddedItem(User $actor, Sale $sale, SaleItem $item, TransactionActionRequest $request): void
    {
        if (strtoupper((string) $item->item_type) !== 'PRODUCT' || ! $item->retail_product_id) {
            return;
        }

        $alreadyPosted = InventoryMovement::query()
            ->where('sale_item_id', $item->id)
            ->where('movement_type', 'RETAIL_SALE')
            ->exists();
        if ($alreadyPosted) return;

        $product = RetailProduct::query()->whereKey($item->retail_product_id)->first();
        if (! $product) throw new DomainException('Retail product is missing from the corrected retail line.');

        $stock = InventoryStock::query()->firstOrCreate(
            ['branch_id' => $sale->branch_id, 'retail_product_id' => $product->id],
            ['business_id' => $sale->business_id, 'on_hand' => 0, 'reserved' => 0],
        );
        $stock = InventoryStock::query()->whereKey($stock->id)->lockForUpdate()->firstOrFail();

        $basicQty = round((float) ($item->basic_quantity_snapshot ?: ($item->quantity * max(0.000001, (float) ($item->selling_to_basic_factor_snapshot ?: 1)))), 4);
        $before = round((float) $stock->on_hand, 4);
        $available = round($before - (float) $stock->reserved, 4);
        if ($available + 0.00005 < $basicQty) {
            throw new DomainException($product->name.' no longer has enough available stock for the approved corrected retail quantity.');
        }

        $after = round($before - $basicQty, 4);
        $stock->update(['on_hand' => $after]);

        InventoryMovement::query()->create([
            'business_id' => $sale->business_id,
            'branch_id' => $sale->branch_id,
            'retail_product_id' => $product->id,
            'sale_id' => $sale->id,
            'sale_item_id' => $item->id,
            'transaction_action_request_id' => $request->id,
            'movement_type' => 'RETAIL_SALE',
            'quantity_change' => -$basicQty,
            'quantity_before' => $before,
            'quantity_after' => $after,
            'unit_cost_snapshot' => (float) $product->unit_cost,
            'reference_code' => $request->code,
            'reason' => 'Approved transaction correction added retail purchase',
            'created_by' => $actor->id,
            'movement_at' => $this->clock->now(),
        ]);
    }

    private function postRefund(User $actor, Sale $sale, TransactionActionRequest $request): array
    {
        if (! $sale->financial_finalized_at) throw new DomainException('Refund requires a financially finalized transaction.');
        $request->loadMissing('lines');
        if ($request->lines->isEmpty()) throw new DomainException('Refund request has no selected lines.');

        $now = $this->clock->now();
        $refund = TransactionRefund::query()->where('transaction_action_request_id', $request->id)->first();
        if ($refund) {
            return ['type' => 'REFUND', 'refund_code' => $refund->code, 'already_posted' => true];
        }

        $refund = TransactionRefund::query()->create([
            'business_id' => $sale->business_id,
            'branch_id' => $sale->branch_id,
            'sale_id' => $sale->id,
            'transaction_action_request_id' => $request->id,
            'code' => $this->codes->next('TRANSACTION_REFUND:'.$now->format('my'), 'REF-'.$now->format('my').'-', 6, $sale->business_id),
            'adjustment_amount' => 0,
            'cash_refund_amount' => 0,
            'balance_reduction_amount' => 0,
            'correction_refund_due_settlement_amount' => 0,
            'refund_method' => $request->refund_method,
            'refund_reference' => $request->refund_reference,
            'reason_code' => $request->reason_code,
            'remarks' => $request->remarks,
            'status' => 'POSTED',
            'processed_by' => $actor->id,
            'processed_at' => $now,
        ]);

        $totals = ['economic' => 0.0, 'cash' => 0.0, 'balance' => 0.0, 'correction_due_settlement' => 0.0];
        $rows = [];

        foreach ($request->lines as $requestLine) {
            /** @var SaleItem $item */
            $item = SaleItem::query()->where('sale_id', $sale->id)->whereKey($requestLine->sale_item_id)->lockForUpdate()->firstOrFail();

            $requested = round((float) $requestLine->refund_amount, 2);
            if ($requested <= self::EPSILON) throw new DomainException('Refund amount must be greater than zero.');

            $correctionDueBefore = max(0, round((float) ($item->refund_due_amount ?? 0), 2));
            $lineCorrectionStatus = strtoupper((string) ($item->correction_status ?? 'ACTIVE'));
            if (in_array($lineCorrectionStatus, ['REMOVED', 'REVERSED'], true)) {
                if ($correctionDueBefore <= self::EPSILON) {
                    throw new DomainException('A removed or reversed line has no remaining refund due.');
                }
                if ($requested - $correctionDueBefore > self::EPSILON) {
                    throw new DomainException('A removed or reversed line can only settle its existing Refund Due.');
                }
            }

            $correctionDueSettlement = min($requested, $correctionDueBefore);
            $economicRefund = round($requested - $correctionDueSettlement, 2);
            $effectiveBefore = max(0, round((float) ($item->effective_net_line_total ?? $item->net_line_total ?? 0), 2));
            if ($economicRefund - $effectiveBefore > self::EPSILON) {
                throw new DomainException($item->item_name_snapshot.' refund exceeds the remaining refundable value plus any correction refund due.');
            }

            $quantity = round((float) $requestLine->refund_quantity, 4);
            if ($economicRefund > self::EPSILON && ($quantity <= 0 || $quantity - (float) $item->quantity > 0.00005)) {
                throw new DomainException($item->item_name_snapshot.' refund quantity exceeds the transaction line quantity.');
            }
            if ($economicRefund > self::EPSILON
                && strtoupper((string) $item->item_type) === 'PRODUCT'
                && $requestLine->return_to_stock) {
                $remainingReturnableQty = max(0, round((float) $item->quantity - (float) ($item->returned_quantity ?? 0), 4));
                if ($quantity - $remainingReturnableQty > 0.00005) {
                    throw new DomainException('Returned quantity exceeds the remaining quantity eligible to be returned to stock.');
                }
            }

            $activeApplied = $this->activeAppliedToItem($item->id);
            if ($correctionDueSettlement > self::EPSILON) {
                $this->trimApplicationsToAmount(
                    $actor,
                    $item,
                    max(0, round($activeApplied - $correctionDueSettlement, 2)),
                    $request,
                    'Approved refund settled a correction refund due.',
                );
                $activeApplied = $this->activeAppliedToItem($item->id);
            }

            $outstandingBefore = max(0, round($effectiveBefore - $activeApplied, 2));
            $balanceReduction = min($economicRefund, $outstandingBefore);
            $cashFromEconomic = round($economicRefund - $balanceReduction, 2);
            if ($cashFromEconomic > self::EPSILON) {
                $this->trimApplicationsToAmount(
                    $actor,
                    $item,
                    max(0, round($activeApplied - $cashFromEconomic, 2)),
                    $request,
                    'Approved customer refund returned previously collected value.',
                );
            }

            $cashRefund = round($correctionDueSettlement + $cashFromEconomic, 2);
            if ($cashRefund > self::EPSILON && trim((string) $request->refund_method) === '') {
                throw new DomainException('Refund Method is required when money is being returned to the customer.');
            }

            $effectiveAfter = max(0, round($effectiveBefore - $economicRefund, 2));
            $newRefundAdjustment = round((float) ($item->refund_adjustment_amount ?? 0) + $economicRefund, 2);
            $newCashRefunded = round((float) ($item->cash_refunded_amount ?? 0) + $cashRefund, 2);
            $newRefundQty = $economicRefund > self::EPSILON
                ? min((float) $item->quantity, round((float) ($item->refunded_quantity ?? 0) + $quantity, 4))
                : (float) ($item->refunded_quantity ?? 0);
            $remainingCorrectionDue = max(0, round($correctionDueBefore - $correctionDueSettlement, 2));
            $refundStatus = $effectiveAfter <= self::EPSILON && $remainingCorrectionDue <= self::EPSILON ? 'FULL' : 'PARTIAL';

            $item->update([
                'refund_adjustment_amount' => $newRefundAdjustment,
                'cash_refunded_amount' => $newCashRefunded,
                'refund_due_amount' => $remainingCorrectionDue,
                'refunded_quantity' => $newRefundQty,
                'effective_net_line_total' => $effectiveAfter,
                'refund_status' => $refundStatus,
                'last_transaction_action_request_id' => $request->id,
                'updated_by' => $actor->id,
            ]);
            $item->refresh();

            $stockReturned = 0.0;
            if (strtoupper((string) $item->item_type) === 'PRODUCT' && $requestLine->return_to_stock && $economicRefund > self::EPSILON) {
                $stockReturned = $this->postProductReturn($actor, $sale, $item, $request, $requestLine, $quantity);
                if ($stockReturned > 0) {
                    $item->update([
                        'returned_quantity' => round((float) ($item->returned_quantity ?? 0) + $stockReturned, 4),
                        'updated_by' => $actor->id,
                    ]);
                    $item->refresh();
                }
            }

            $package = null;
            if ($economicRefund > self::EPSILON
                && strtoupper((string) $item->item_type) === 'PACKAGE'
                && in_array(strtoupper((string) $request->reason_code), ['PACKAGE_REFUND', 'UNUSED_PACKAGE_BALANCE'], true)) {
                $package = $this->refundUnusedPackageBalance($actor, $item, $request, $economicRefund);
            }

            $refund->lines()->create([
                'transaction_action_request_line_id' => $requestLine->id,
                'sale_item_id' => $item->id,
                'refund_quantity' => $quantity,
                'requested_amount' => $requested,
                'economic_adjustment_amount' => $economicRefund,
                'cash_refund_amount' => $cashRefund,
                'balance_reduction_amount' => $balanceReduction,
                'correction_refund_due_settlement_amount' => $correctionDueSettlement,
                'return_to_stock' => $requestLine->return_to_stock,
                'stock_quantity_returned' => $stockReturned,
                'item_snapshot' => $requestLine->original_snapshot,
            ]);

            $totals['economic'] = round($totals['economic'] + $economicRefund, 2);
            $totals['cash'] = round($totals['cash'] + $cashRefund, 2);
            $totals['balance'] = round($totals['balance'] + $balanceReduction, 2);
            $totals['correction_due_settlement'] = round($totals['correction_due_settlement'] + $correctionDueSettlement, 2);
            $rows[] = [
                'sale_item_id' => $item->id,
                'item' => $item->item_name_snapshot,
                'requested_amount' => $requested,
                'economic_adjustment' => $economicRefund,
                'cash_refund' => $cashRefund,
                'balance_reduction' => $balanceReduction,
                'correction_refund_due_settlement' => $correctionDueSettlement,
                'remaining_correction_refund_due' => $remainingCorrectionDue,
                'returned_to_stock' => (bool) $requestLine->return_to_stock,
                'stock_quantity_returned' => $stockReturned,
                'package' => $package,
            ];
        }

        $refund->update([
            'adjustment_amount' => $totals['economic'],
            'cash_refund_amount' => $totals['cash'],
            'balance_reduction_amount' => $totals['balance'],
            'correction_refund_due_settlement_amount' => $totals['correction_due_settlement'],
        ]);

        return ['type' => 'REFUND', 'refund_code' => $refund->code, 'totals' => $totals, 'lines' => $rows];
    }

    private function postReversal(User $actor, Sale $sale, TransactionActionRequest $request): array
    {
        if (strtoupper((string) $sale->status) === 'REVERSED') {
            throw new DomainException('This transaction has already been reversed.');
        }

        // Fail before changing anything if a package originating from this sale
        // already has later package-credit usage that belongs to another visit.
        foreach ($sale->items->where('item_type', 'PACKAGE') as $item) {
            $this->assertOriginPackageCanBeCancelled($item);
        }

        $rows = [];
        foreach ($sale->items as $itemRecord) {
            /** @var SaleItem $item */
            $item = SaleItem::query()->whereKey($itemRecord->id)->lockForUpdate()->firstOrFail();
            // Preserve the amount of real money still applied to this line before
            // applications are counter-posted. Reversal invalidates the sale, but
            // it does not pretend received cash never happened. That amount becomes
            // Refund Due until a separately approved Refund actually returns it.
            $appliedBeforeReversal = strtoupper((string) $item->item_type) === 'SERVICE_CREDIT_USE'
                ? 0.0
                : $this->activeAppliedToItem($item->id);
            $inventory = $this->reconcileOperationalInventoryForCorrection($actor, $sale, $item, $request, null, 0, true);
            $serviceCredit = $this->reverseServiceCreditEffects($actor, $sale, $item, $request, null);
            $package = $this->cancelOriginPackageIfSafe($actor, $item, $request, true);

            $item->update([
                'effective_net_line_total' => 0,
                'refund_due_amount' => round($appliedBeforeReversal, 2),
                'refund_status' => $appliedBeforeReversal > self::EPSILON ? 'DUE' : ($item->refund_status ?? 'NONE'),
                'correction_status' => 'REVERSED',
                'last_transaction_action_request_id' => $request->id,
                'status' => 'REVERSED',
                'updated_by' => $actor->id,
            ]);
            $this->syncOperationalSourceLine($actor, $item->fresh(), 'REMOVE', 0, $item->assigned_personnel_id, $item->assigned_personnel_name_snapshot, $request);

            $rows[] = [
                'sale_item_id' => $item->id,
                'item' => $item->item_name_snapshot,
                'inventory' => $inventory,
                'service_credit' => $serviceCredit,
                'package' => $package,
                'refund_due' => round($appliedBeforeReversal, 2),
            ];
        }

        $this->reverseAllSaleApplications($actor, $sale, $request);

        $sale->update([
            'status' => 'REVERSED',
            'reversed_at' => $this->clock->now(),
            'reversed_by' => $actor->id,
            'reversal_action_request_id' => $request->id,
            'effective_net_amount' => 0,
            'net_amount' => 0,
            'outstanding_balance' => 0,
            'applied_amount' => 0,
            'updated_by' => $actor->id,
        ]);

        return ['type' => 'REVERSAL', 'lines' => $rows, 'whole_transaction' => true];
    }

    private function reconcileSaleFinancialPosition(User $actor, Sale $sale, bool $reversed): Sale
    {
        $sale = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
        $sale->load('items');

        if ($reversed || strtoupper((string) $sale->status) === 'REVERSED') {
            $refundDue = round((float) $sale->items->sum(fn (SaleItem $item) => max(0, (float) ($item->refund_due_amount ?? 0))), 2);
            $cashRefunded = round((float) $sale->items->sum(fn (SaleItem $item) => max(0, (float) ($item->cash_refunded_amount ?? 0))), 2);
            $sale->update([
                'status' => 'REVERSED',
                'net_amount' => 0,
                'effective_net_amount' => 0,
                'applied_amount' => 0,
                'outstanding_balance' => 0,
                'refund_due_amount' => $refundDue,
                'refund_status' => $refundDue > self::EPSILON ? 'DUE' : ($cashRefunded > self::EPSILON ? 'FULL' : 'NONE'),
                'cash_refunded_amount' => $cashRefunded,
                'updated_by' => $actor->id,
            ]);
            return $sale->fresh('items');
        }

        $gross = 0.0;
        $discount = 0.0;
        $effective = 0.0;
        $appliedTotal = 0.0;
        $outstanding = 0.0;
        $refundDue = 0.0;
        $correctionAdjustments = 0.0;
        $refundAdjustments = 0.0;
        $cashRefunded = 0.0;
        $chargeableCount = 0;
        $serviceCreditCount = 0;
        $financiallyFinalized = $sale->financial_finalized_at !== null;

        foreach ($sale->items as $item) {
            $removed = in_array(strtoupper((string) ($item->correction_status ?? 'ACTIVE')), ['REMOVED', 'REVERSED'], true);
            $isServiceCredit = strtoupper((string) $item->item_type) === 'SERVICE_CREDIT_USE';
            $itemEffective = $removed ? 0.0 : max(0, round((float) ($item->effective_net_line_total ?? $item->net_line_total ?? $item->line_total), 2));
            $activeApplied = $financiallyFinalized && ! $isServiceCredit ? $this->activeAppliedToItem($item->id) : 0.0;
            $balance = $financiallyFinalized && ! $isServiceCredit ? max(0, round($itemEffective - $activeApplied, 2)) : 0.0;
            $lineRefundDue = $financiallyFinalized && ! $isServiceCredit ? max(0, round($activeApplied - $itemEffective, 2)) : 0.0;

            if (! $removed) {
                $gross += (float) $item->line_total;
                $discount += (float) ($item->discount_amount ?? 0);
            }
            $effective += $itemEffective;
            $appliedTotal += $activeApplied;
            $outstanding += $balance;
            $refundDue += $lineRefundDue;
            $correctionAdjustments += (float) ($item->correction_adjustment_amount ?? 0);
            $refundAdjustments += (float) ($item->refund_adjustment_amount ?? 0);
            $cashRefunded += (float) ($item->cash_refunded_amount ?? 0);

            if ($isServiceCredit && ! $removed) $serviceCreditCount++;
            elseif (! $removed) $chargeableCount++;

            if ($financiallyFinalized) {
                $itemStatus = $removed
                    ? ($item->correction_status === 'REVERSED' ? 'REVERSED' : 'CORRECTED_REMOVED')
                    : ($isServiceCredit ? 'SERVICE_CREDIT' : ($itemEffective <= self::EPSILON && (float) $item->refund_adjustment_amount > self::EPSILON
                        ? 'REFUNDED'
                        : ($balance <= self::EPSILON ? 'PAID' : 'ON_ACCOUNT')));
                $lineRefundStatus = $lineRefundDue > self::EPSILON
                    ? 'DUE'
                    : (((float) ($item->refund_adjustment_amount ?? 0) > self::EPSILON || (float) ($item->cash_refunded_amount ?? 0) > self::EPSILON)
                        ? ($itemEffective <= self::EPSILON ? 'FULL' : 'PARTIAL')
                        : 'NONE');

                $item->update([
                    'applied_amount' => round($activeApplied, 2),
                    'balance_amount' => round($balance, 2),
                    'refund_due_amount' => round($lineRefundDue, 2),
                    'refund_status' => $lineRefundStatus,
                    'status' => $itemStatus,
                    'updated_by' => $actor->id,
                ]);
            } else {
                $item->update([
                    'balance_amount' => $isServiceCredit ? 0 : $itemEffective,
                    'refund_due_amount' => 0,
                    'status' => $removed ? 'CORRECTED_REMOVED' : ($isServiceCredit ? 'SERVICE_CREDIT' : 'FOR_CHECKOUT'),
                    'updated_by' => $actor->id,
                ]);
            }
        }

        $effective = round($effective, 2);
        $outstanding = round($outstanding, 2);
        $refundDue = round($refundDue, 2);
        $refundStatus = $refundDue > self::EPSILON
            ? 'DUE'
            : (($refundAdjustments <= self::EPSILON && $cashRefunded <= self::EPSILON)
                ? 'NONE'
                : ($effective <= self::EPSILON ? 'FULL' : 'PARTIAL'));

        if ($financiallyFinalized) {
            $status = $chargeableCount === 0 && $serviceCreditCount > 0
                ? 'SERVICE_CREDIT'
                : ($outstanding <= self::EPSILON ? 'PAID' : 'ON_ACCOUNT');
        } else {
            $receivedBeforeCheckout = max(0, round((float) $sale->total_paid, 2));
            $outstanding = max(0, round($effective - min($effective, $receivedBeforeCheckout), 2));
            $status = $effective > self::EPSILON && $receivedBeforeCheckout + self::EPSILON >= $effective
                ? 'AWAITING_CONFIRM'
                : ($receivedBeforeCheckout > self::EPSILON ? 'PARTIALLY_PAID' : 'FOR_CHECKOUT');
            $appliedTotal = 0.0;
            $refundDue = 0.0;
        }

        $sale->update([
            'gross_amount' => round($gross, 2),
            'discount_amount' => round($discount, 2),
            'net_amount' => $effective,
            'effective_net_amount' => $effective,
            'applied_amount' => round($appliedTotal, 2),
            'outstanding_balance' => $outstanding,
            'correction_adjustment_amount' => round($correctionAdjustments, 2),
            'refund_adjustment_amount' => round($refundAdjustments, 2),
            'cash_refunded_amount' => round($cashRefunded, 2),
            'refund_due_amount' => $refundDue,
            'refund_status' => $refundStatus,
            'status' => $status,
            'updated_by' => $actor->id,
        ]);

        return $sale->fresh('items');
    }

    private function trimApplicationsToAmount(User $actor, SaleItem $item, float $targetApplied, TransactionActionRequest $request, string $reason): void
    {
        $targetApplied = round(max(0, $targetApplied), 2);
        $applications = PaymentApplication::query()
            ->where('sale_id', $item->sale_id)
            ->where('target_sale_item_id', $item->id)
            ->where('status', 'ACTIVE')
            ->orderByDesc('allocation_order')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->get();

        $current = round((float) $applications->sum('amount'), 2);
        $excess = round(max(0, $current - $targetApplied), 2);
        if ($excess <= self::EPSILON) return;

        foreach ($applications as $application) {
            if ($excess <= self::EPSILON) break;
            $amount = round((float) $application->amount, 2);
            $remove = min($amount, $excess);
            $retain = round($amount - $remove, 2);

            $application->update([
                'status' => 'REVERSED',
                'reversed_by' => $actor->id,
                'reversal_reason' => $reason.' ['.$request->code.']',
                'reversed_at' => $this->clock->now(),
                'transaction_action_request_id' => $request->id,
            ]);

            if ($retain > self::EPSILON) {
                $now = $this->clock->now();
                PaymentApplication::query()->create([
                    'code' => $this->codes->next('PAYMENT_APPLICATION:'.$now->format('my'), 'PAP-'.$now->format('my').'-', 7, $request->business_id),
                    'application_key' => 'TXA:'.$request->id.':PAP:'.$application->id.':RETAIN',
                    'payment_id' => $application->payment_id,
                    'sale_id' => $application->sale_id,
                    'target_booking_id' => $application->target_booking_id,
                    'target_type' => $application->target_type,
                    'target_sale_item_id' => $application->target_sale_item_id,
                    'package_enrollment_id' => $application->package_enrollment_id,
                    'source_sale_id' => $application->source_sale_id,
                    'amount' => $retain,
                    'allocation_order' => $application->allocation_order,
                    'status' => 'ACTIVE',
                    'target_snapshot' => array_merge($application->target_snapshot ?: [], [
                        'transaction_action_request' => $request->code,
                        'replacement_of_application_id' => $application->id,
                    ]),
                    'created_by' => $actor->id,
                    'transaction_action_request_id' => $request->id,
                ]);
            }

            $excess = round($excess - $remove, 2);
        }

        if ($excess > self::EPSILON) {
            throw new DomainException('Unable to reconcile payment applications for the approved transaction action.');
        }
    }

    private function reverseAllSaleApplications(User $actor, Sale $sale, TransactionActionRequest $request): void
    {
        PaymentApplication::query()
            ->where('sale_id', $sale->id)
            ->where('status', 'ACTIVE')
            ->lockForUpdate()
            ->get()
            ->each(function (PaymentApplication $application) use ($actor, $request) {
                $application->update([
                    'status' => 'REVERSED',
                    'reversed_by' => $actor->id,
                    'reversal_reason' => 'Whole transaction reversal ['.$request->code.']',
                    'reversed_at' => $this->clock->now(),
                    'transaction_action_request_id' => $request->id,
                ]);
            });
    }

    private function reconcileOperationalInventoryForCorrection(
        User $actor,
        Sale $sale,
        SaleItem $item,
        TransactionActionRequest $request,
        ?TransactionActionRequestLine $requestLine,
        int $targetQuantity,
        bool $reversal = false,
    ): array {
        $type = strtoupper((string) $item->item_type);
        $effects = [];

        if ($type === 'PRODUCT' && $item->retail_product_id) {
            $effect = $this->reconcileProductSaleMovement($actor, $sale, $item, $request, $requestLine, $targetQuantity, $reversal);
            if ($effect) $effects[] = $effect;
        }

        if (in_array($type, ['SERVICE', 'PACKAGE', 'SERVICE_CREDIT_USE'], true) && $item->visit_service_line_id) {
            $effects = array_merge($effects, $this->reconcileServiceConsumption($actor, $sale, $item, $request, $requestLine, $targetQuantity, $reversal));
        }

        return $effects;
    }

    private function reconcileProductSaleMovement(
        User $actor,
        Sale $sale,
        SaleItem $item,
        TransactionActionRequest $request,
        ?TransactionActionRequestLine $requestLine,
        int $targetQuantity,
        bool $reversal,
    ): ?array {
        $hasSource = InventoryMovement::query()
            ->where('sale_item_id', $item->id)
            ->where('movement_type', 'RETAIL_SALE')
            ->exists();
        if (! $hasSource) return null; // Operations retail line may not have posted inventory in older data.

        $currentNet = round((float) InventoryMovement::query()->where('sale_item_id', $item->id)->sum('quantity_change'), 4);
        $factor = max(0.000001, (float) ($item->selling_to_basic_factor_snapshot ?: 1));
        $returnedSellingQty = max(0, (float) ($item->returned_quantity ?? 0));
        $physicalSellingQty = $reversal ? 0.0 : max(0, (float) $targetQuantity - $returnedSellingQty);
        $desiredNet = round(-$physicalSellingQty * $factor, 4);
        $delta = round($desiredNet - $currentNet, 4);
        if (abs($delta) <= 0.00005) return null;

        $stock = InventoryStock::query()->firstOrCreate(
            ['branch_id' => $sale->branch_id, 'retail_product_id' => $item->retail_product_id],
            ['business_id' => $sale->business_id, 'on_hand' => 0, 'reserved' => 0],
        );
        $stock = InventoryStock::query()->whereKey($stock->id)->lockForUpdate()->firstOrFail();
        $before = round((float) $stock->on_hand, 4);
        if ($delta < 0 && round($before - (float) $stock->reserved, 4) + 0.00005 < abs($delta)) {
            throw new DomainException($item->item_name_snapshot.' has insufficient stock for the approved quantity increase.');
        }
        $after = round($before + $delta, 4);
        $stock->update(['on_hand' => $after]);

        $effectKey = 'TXA:'.$request->id.':PRODUCT:'.$item->id.':'.($reversal ? 'REVERSAL' : 'CORRECTION');
        $movement = InventoryMovement::query()->where('action_effect_key', $effectKey)->first();
        if (! $movement) {
            $movement = InventoryMovement::query()->create([
                'business_id' => $sale->business_id,
                'branch_id' => $sale->branch_id,
                'retail_product_id' => $item->retail_product_id,
                'sale_id' => $sale->id,
                'sale_item_id' => $item->id,
                'transaction_action_request_id' => $request->id,
                'transaction_action_request_line_id' => $requestLine?->id,
                'action_effect_key' => $effectKey,
                'movement_type' => $reversal ? 'TRANSACTION_REVERSAL' : 'TRANSACTION_CORRECTION',
                'quantity_change' => $delta,
                'quantity_before' => $before,
                'quantity_after' => $after,
                'unit_cost_snapshot' => (float) ($item->retailProduct?->unit_cost ?? 0),
                'reference_code' => $request->code,
                'reason' => $request->reason_label.' · '.$request->remarks,
                'created_by' => $actor->id,
                'movement_at' => $this->clock->now(),
            ]);
        }

        return ['movement_id' => $movement->id, 'product_id' => $item->retail_product_id, 'quantity_change' => $delta];
    }

    private function reconcileServiceConsumption(
        User $actor,
        Sale $sale,
        SaleItem $item,
        TransactionActionRequest $request,
        ?TransactionActionRequestLine $requestLine,
        int $targetQuantity,
        bool $reversal,
    ): array {
        /** @var ServiceConsumption|null $consumption */
        $consumption = ServiceConsumption::query()
            ->where('visit_service_line_id', $item->visit_service_line_id)
            ->with('lines.product')
            ->lockForUpdate()
            ->first();
        if (! $consumption) return [];

        $originalServiceQty = max(0.0001, (float) $consumption->service_quantity);
        $target = $reversal ? 0.0 : max(0, (float) $targetQuantity);
        $effects = [];
        $effectiveTotalCost = 0.0;

        foreach ($consumption->lines as $line) {
            $originalBasic = (float) $line->basic_quantity;
            $desiredNet = round(-$originalBasic * ($target / $originalServiceQty), 4);
            $effectiveTotalCost = round($effectiveTotalCost + (abs($desiredNet) * (float) $line->weighted_average_basic_cost), 2);
            $originalMovementQty = (float) InventoryMovement::query()->whereKey($line->inventory_movement_id)->value('quantity_change');
            $adjustments = (float) InventoryMovement::query()
                ->where('source_service_consumption_line_id', $line->id)
                ->sum('quantity_change');
            $currentNet = round($originalMovementQty + $adjustments, 4);
            $delta = round($desiredNet - $currentNet, 4);
            if (abs($delta) <= 0.00005) continue;

            $stock = InventoryStock::query()->firstOrCreate(
                ['branch_id' => $sale->branch_id, 'retail_product_id' => $line->retail_product_id],
                ['business_id' => $sale->business_id, 'on_hand' => 0, 'reserved' => 0],
            );
            $stock = InventoryStock::query()->whereKey($stock->id)->lockForUpdate()->firstOrFail();
            $before = round((float) $stock->on_hand, 4);
            $after = round($before + $delta, 4); // service corrections may still create negative stock
            $stock->update(['on_hand' => $after]);

            $effectKey = 'TXA:'.$request->id.':SERVICE_CONSUMPTION_LINE:'.$line->id;
            $movement = InventoryMovement::query()->where('action_effect_key', $effectKey)->first();
            if (! $movement) {
                $movement = InventoryMovement::query()->create([
                    'business_id' => $sale->business_id,
                    'branch_id' => $sale->branch_id,
                    'retail_product_id' => $line->retail_product_id,
                    'sale_id' => $sale->id,
                    'sale_item_id' => $item->id,
                    'transaction_action_request_id' => $request->id,
                    'transaction_action_request_line_id' => $requestLine?->id,
                    'source_service_consumption_line_id' => $line->id,
                    'action_effect_key' => $effectKey,
                    'movement_type' => $reversal ? 'SERVICE_CONSUMPTION_REVERSAL' : 'SERVICE_CONSUMPTION_CORRECTION',
                    'quantity_change' => $delta,
                    'quantity_before' => $before,
                    'quantity_after' => $after,
                    'unit_cost_snapshot' => (float) $line->weighted_average_basic_cost,
                    'reference_code' => $request->code,
                    'reason' => $request->reason_label.' · '.$request->remarks,
                    'created_by' => $actor->id,
                    'movement_at' => $this->clock->now(),
                ]);
            }
            $effects[] = ['movement_id' => $movement->id, 'material_id' => $line->retail_product_id, 'quantity_change' => $delta];
        }

        $consumption->update([
            'effective_service_quantity' => $target,
            'total_cost' => $effectiveTotalCost,
            'correction_status' => $target <= 0.00005 ? ($reversal ? 'REVERSED' : 'REMOVED') : ($target == $originalServiceQty ? 'ACTIVE' : 'CORRECTED'),
            'last_transaction_action_request_id' => $request->id,
        ]);

        return $effects;
    }

    private function postProductReturn(
        User $actor,
        Sale $sale,
        SaleItem $item,
        TransactionActionRequest $request,
        TransactionActionRequestLine $requestLine,
        float $sellingQuantity,
    ): float {
        $sourceExists = InventoryMovement::query()
            ->where('sale_item_id', $item->id)
            ->where('movement_type', 'RETAIL_SALE')
            ->exists();
        if (! $sourceExists) {
            throw new DomainException($item->item_name_snapshot.' cannot be returned to stock because this transaction has no posted retail inventory deduction.');
        }

        $factor = max(0.000001, (float) ($item->selling_to_basic_factor_snapshot ?: 1));
        $basicQuantity = round($sellingQuantity * $factor, 4);
        $currentNet = round((float) InventoryMovement::query()->where('sale_item_id', $item->id)->sum('quantity_change'), 4);
        if ($basicQuantity - max(0, -$currentNet) > 0.00005) {
            throw new DomainException($item->item_name_snapshot.' return quantity exceeds the quantity still outside inventory.');
        }

        $effectKey = 'TXA:'.$request->id.':REFUND_RETURN:'.$item->id;
        if ($existing = InventoryMovement::query()->where('action_effect_key', $effectKey)->first()) {
            return (float) $existing->quantity_change;
        }

        $stock = InventoryStock::query()->firstOrCreate(
            ['branch_id' => $sale->branch_id, 'retail_product_id' => $item->retail_product_id],
            ['business_id' => $sale->business_id, 'on_hand' => 0, 'reserved' => 0],
        );
        $stock = InventoryStock::query()->whereKey($stock->id)->lockForUpdate()->firstOrFail();
        $before = round((float) $stock->on_hand, 4);
        $after = round($before + $basicQuantity, 4);
        $stock->update(['on_hand' => $after]);

        InventoryMovement::query()->create([
            'business_id' => $sale->business_id,
            'branch_id' => $sale->branch_id,
            'retail_product_id' => $item->retail_product_id,
            'sale_id' => $sale->id,
            'sale_item_id' => $item->id,
            'transaction_action_request_id' => $request->id,
            'transaction_action_request_line_id' => $requestLine->id,
            'action_effect_key' => $effectKey,
            'movement_type' => 'REFUND_RETURN',
            'quantity_change' => $basicQuantity,
            'quantity_before' => $before,
            'quantity_after' => $after,
            'unit_cost_snapshot' => (float) ($item->retailProduct?->unit_cost ?? 0),
            'reference_code' => $request->code,
            'reason' => 'Customer return to stock · '.$request->reason_label,
            'created_by' => $actor->id,
            'movement_at' => $this->clock->now(),
        ]);

        return $basicQuantity;
    }

    private function reverseServiceCreditEffects(
        User $actor,
        Sale $sale,
        SaleItem $item,
        TransactionActionRequest $request,
        ?TransactionActionRequestLine $requestLine,
    ): ?array {
        if (strtoupper((string) $item->item_type) !== 'SERVICE_CREDIT_USE' || ! $item->visit_service_line_id) return null;

        $usage = ServiceCreditUsage::query()
            ->where('visit_service_line_id', $item->visit_service_line_id)
            ->whereNull('reversed_at')
            ->lockForUpdate()
            ->first();
        if (! $usage) return null;

        $credit = ServiceCredit::query()->whereKey($usage->service_credit_id)->lockForUpdate()->firstOrFail();
        $sessions = max(1, (int) $usage->sessions_used);
        $newUsed = max(0, (int) $credit->sessions_used - $sessions);
        $newRemaining = min((int) $credit->sessions_purchased, (int) $credit->sessions_remaining + $sessions);
        $credit->update([
            'sessions_used' => $newUsed,
            'sessions_remaining' => $newRemaining,
            'status' => $newRemaining > 0 ? 'ACTIVE' : 'USED',
            'updated_by' => $actor->id,
        ]);
        $usage->update([
            'reversed_at' => $this->clock->now(),
            'reversed_by' => $actor->id,
            'reversal_reason' => $request->reason_label.' · '.$request->remarks,
            'reversal_action_request_id' => $request->id,
        ]);

        $enrollment = PackageEnrollment::query()->whereKey($usage->package_enrollment_id)->lockForUpdate()->firstOrFail();
        $enrollment->update([
            'status' => $newRemaining > 0 ? 'ACTIVE' : 'COMPLETED',
            'completed_at' => $newRemaining > 0 ? null : $enrollment->completed_at,
            'updated_by' => $actor->id,
        ]);

        $movementKey = 'TXA:'.$request->id.':SERVICE_CREDIT_RESTORE:'.$usage->id;
        $ledger = ServiceCreditLedger::query()->where('movement_key', $movementKey)->first();
        if (! $ledger) {
            $now = $this->clock->now();
            $ledger = ServiceCreditLedger::query()->create([
                'business_id' => $sale->business_id,
                'code' => $this->codes->next('SERVICE_CREDIT_LEDGER:'.$now->format('my'), 'SCM-'.$now->format('my').'-', 8, $sale->business_id),
                'movement_key' => $movementKey,
                'service_credit_id' => $credit->id,
                'package_enrollment_id' => $enrollment->id,
                'customer_id' => $enrollment->customer_id,
                'service_id' => $enrollment->service_id,
                'movement_type' => 'RESTORE',
                'sessions' => $sessions,
                'booking_id' => $sale->booking_id,
                'visit_id' => $sale->visit_id,
                'visit_service_line_id' => $item->visit_service_line_id,
                'sale_id' => $sale->id,
                'sale_item_id' => $item->id,
                'notes' => 'Approved transaction correction/reversal restored consumed service credit.',
                'processed_by' => $actor->id,
            ]);
        }

        return ['service_credit_id' => $credit->id, 'restored_sessions' => $sessions, 'ledger_id' => $ledger->id];
    }

    private function assertOriginPackageCanBeCancelled(SaleItem $item): void
    {
        if (strtoupper((string) $item->item_type) !== 'PACKAGE' || ! $item->package_enrollment_id) return;
        $enrollment = PackageEnrollment::query()->whereKey($item->package_enrollment_id)->with('serviceCredit')->first();
        if (! $enrollment) return;

        $laterUsageExists = ServiceCreditUsage::query()
            ->where('package_enrollment_id', $enrollment->id)
            ->whereNull('reversed_at')
            ->where(function ($query) use ($item) {
                $query->whereNull('visit_service_line_id');
                if ($item->visit_service_line_id) $query->orWhere('visit_service_line_id', '!=', $item->visit_service_line_id);
            })
            ->exists();
        if ($laterUsageExists) {
            throw new DomainException($item->item_name_snapshot.' cannot be fully reversed because package credits have already been used on a later visit. Reverse/correct those later usages first.');
        }
    }

    private function cancelOriginPackageIfSafe(User $actor, SaleItem $item, TransactionActionRequest $request, bool $reversal): ?array
    {
        if (strtoupper((string) $item->item_type) !== 'PACKAGE' || ! $item->package_enrollment_id) return null;
        $this->assertOriginPackageCanBeCancelled($item);

        $enrollment = PackageEnrollment::query()->whereKey($item->package_enrollment_id)->lockForUpdate()->first();
        if (! $enrollment) return null;
        $credit = ServiceCredit::query()->where('package_enrollment_id', $enrollment->id)->lockForUpdate()->first();
        $remaining = (int) ($credit?->sessions_remaining ?? 0);

        if ($credit) {
            $credit->update(['sessions_remaining' => 0, 'status' => 'CANCELLED', 'updated_by' => $actor->id]);
            $movementKey = 'TXA:'.$request->id.':PACKAGE_CANCEL:'.$enrollment->id;
            if (! ServiceCreditLedger::query()->where('movement_key', $movementKey)->exists()) {
                $now = $this->clock->now();
                ServiceCreditLedger::query()->create([
                    'business_id' => $enrollment->business_id,
                    'code' => $this->codes->next('SERVICE_CREDIT_LEDGER:'.$now->format('my'), 'SCM-'.$now->format('my').'-', 8, $enrollment->business_id),
                    'movement_key' => $movementKey,
                    'service_credit_id' => $credit->id,
                    'package_enrollment_id' => $enrollment->id,
                    'customer_id' => $enrollment->customer_id,
                    'service_id' => $enrollment->service_id,
                    'movement_type' => 'CANCEL',
                    'sessions' => $remaining,
                    'booking_id' => $enrollment->booking_id,
                    'visit_id' => $enrollment->visit_id,
                    'visit_service_line_id' => $enrollment->visit_service_line_id,
                    'sale_id' => $item->sale_id,
                    'sale_item_id' => $item->id,
                    'notes' => 'Unused package entitlement cancelled by approved transaction '.strtolower($request->action_type).'.',
                    'processed_by' => $actor->id,
                ]);
            }
        }

        $enrollment->update([
            'status' => 'CANCELLED',
            'cancelled_at' => $this->clock->now(),
            'cancellation_reason' => $request->reason_label.' · '.$request->remarks,
            'updated_by' => $actor->id,
        ]);

        return ['package_enrollment_id' => $enrollment->id, 'cancelled_unused_sessions' => $remaining, 'reversal' => $reversal];
    }

    private function refundUnusedPackageBalance(User $actor, SaleItem $item, TransactionActionRequest $request, float $requestedAmount): ?array
    {
        if (! $item->package_enrollment_id) return null;
        $enrollment = PackageEnrollment::query()->whereKey($item->package_enrollment_id)->lockForUpdate()->first();
        if (! $enrollment) return null;
        $credit = ServiceCredit::query()->where('package_enrollment_id', $enrollment->id)->lockForUpdate()->first();
        if (! $credit || (int) $credit->sessions_remaining <= 0) return ['cancelled_unused_sessions' => 0];

        $purchased = max(1, (int) $credit->sessions_purchased);
        $remaining = (int) $credit->sessions_remaining;
        $packageValue = max(0, (float) ($item->net_line_total ?? $item->line_total));
        $unusedValue = round($packageValue * ($remaining / $purchased), 2);
        if (abs($requestedAmount - $unusedValue) > 0.01) {
            throw new DomainException('Unused Package Balance refund must match the current unused-session value of ₱'.number_format($unusedValue, 2).'.');
        }

        $credit->update(['sessions_remaining' => 0, 'status' => 'CANCELLED', 'updated_by' => $actor->id]);
        $enrollment->update([
            'status' => 'CANCELLED',
            'cancelled_at' => $this->clock->now(),
            'cancellation_reason' => $request->reason_label.' · '.$request->remarks,
            'updated_by' => $actor->id,
        ]);

        $movementKey = 'TXA:'.$request->id.':PACKAGE_UNUSED_REFUND:'.$enrollment->id;
        if (! ServiceCreditLedger::query()->where('movement_key', $movementKey)->exists()) {
            $now = $this->clock->now();
            ServiceCreditLedger::query()->create([
                'business_id' => $enrollment->business_id,
                'code' => $this->codes->next('SERVICE_CREDIT_LEDGER:'.$now->format('my'), 'SCM-'.$now->format('my').'-', 8, $enrollment->business_id),
                'movement_key' => $movementKey,
                'service_credit_id' => $credit->id,
                'package_enrollment_id' => $enrollment->id,
                'customer_id' => $enrollment->customer_id,
                'service_id' => $enrollment->service_id,
                'movement_type' => 'CANCEL',
                'sessions' => $remaining,
                'booking_id' => $enrollment->booking_id,
                'visit_id' => $enrollment->visit_id,
                'visit_service_line_id' => $enrollment->visit_service_line_id,
                'sale_id' => $item->sale_id,
                'sale_item_id' => $item->id,
                'notes' => 'Unused package sessions cancelled by approved refund.',
                'processed_by' => $actor->id,
            ]);
        }

        return ['cancelled_unused_sessions' => $remaining, 'unused_value' => $unusedValue];
    }

    private function syncOperationalSourceLine(
        User $actor,
        SaleItem $item,
        string $lineAction,
        int $quantity,
        ?int $personnelId,
        ?string $personnelName,
        TransactionActionRequest $request,
    ): void {
        if ($item->visit_service_line_id) {
            $line = $item->visitServiceLine()->lockForUpdate()->first();
            if ($line) {
                $completion = $line->completionEvent()->lockForUpdate()->first();
                if ($lineAction === 'REMOVE') {
                    $line->update([
                        'status' => 'CANCELLED',
                        'cancelled_at' => $this->clock->now(),
                        'cancelled_by' => $actor->id,
                        'cancellation_reason' => 'Approved transaction correction/reversal · '.$request->code,
                        'updated_by' => $actor->id,
                    ]);
                    if ($completion) {
                        $completion->update([
                            'status' => 'REVERSED',
                            'last_transaction_action_request_id' => $request->id,
                        ]);
                    }
                } else {
                    $line->update([
                        'quantity' => $quantity,
                        'assigned_personnel_id' => $personnelId,
                        'assigned_personnel_name_snapshot' => $personnelName,
                        'updated_by' => $actor->id,
                    ]);
                    if ($completion) {
                        $completion->update([
                            'quantity' => $quantity,
                            'assigned_personnel_id' => $personnelId,
                            'assigned_personnel_name_snapshot' => $personnelName,
                            'status' => 'ACTIVE',
                            'last_transaction_action_request_id' => $request->id,
                        ]);
                    }
                }
            }
        }

        if ($item->visit_retail_line_id) {
            $line = $item->visitRetailLine()->lockForUpdate()->first();
            if ($line) {
                if ($lineAction === 'REMOVE') {
                    // Preserve the originally completed retail line for audit, but mark it
                    // non-effective so corrected reporting/waterfall logic can ignore it.
                    $line->update([
                        'correction_status' => 'REMOVED',
                        'last_transaction_action_request_id' => $request->id,
                        'updated_by' => $actor->id,
                    ]);
                } else {
                    $line->update([
                        'quantity' => $quantity,
                        'unit_price_snapshot' => $item->unit_price,
                        'assigned_personnel_id' => $personnelId,
                        'assigned_personnel_name_snapshot' => $personnelName,
                        'correction_status' => 'CORRECTED',
                        'last_transaction_action_request_id' => $request->id,
                        'updated_by' => $actor->id,
                    ]);
                }
            }
        }
    }

    private function reconcileCommissions(User $actor, Sale $sale, TransactionActionRequest $request): array
    {
        // Before Checkout, Correction only changes the effective operational/sales basis.
        // Commission recognition remains a Checkout responsibility so no commission is created early.
        if (! $sale->financial_finalized_at) {
            return [
                'adjustments_created' => [],
                'recoveries_created' => [],
                'count' => 0,
                'deferred_until_checkout' => true,
            ];
        }

        $sale = Sale::query()->whereKey($sale->id)->with([
            'items.service.category', 'items.retailProduct', 'items.assignedPersonnel.position',
            'items.visitServiceLine.packageEnrollment', 'items.packageEnrollment',
        ])->firstOrFail();

        $created = [];
        $recoveries = [];
        $touchedDays = [];
        $today = $this->clock->today();

        foreach ($sale->items as $item) {
            $current = CommissionEarning::query()
                ->where('sale_item_id', $item->id)
                ->where('status', '!=', 'REVERSED')
                ->lockForUpdate()
                ->get();

            $expected = strtoupper((string) $sale->status) === 'REVERSED'
                ? null
                : $this->commissionEarnings->expectedForSaleItem($sale, $item);

            $personnelIds = $current->pluck('personnel_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
            if ($expected && isset($expected['personnel']) && $expected['personnel']?->id) {
                $personnelIds->push((int) $expected['personnel']->id);
            }
            $personnelIds = $personnelIds->unique()->values();

            foreach ($personnelIds as $personnelId) {
                $rows = $current->where('personnel_id', $personnelId)->values();
                $currentAmount = round((float) $rows->sum('commission_amount'), 2);
                $targetAmount = $expected && (int) $expected['personnel']->id === (int) $personnelId
                    ? round((float) $expected['commission_amount'], 2)
                    : 0.0;
                $delta = round($targetAmount - $currentAmount, 2);
                if (abs($delta) <= self::EPSILON) continue;

                $personnel = Personnel::query()->find($personnelId);
                if (! $personnel) throw new DomainException('Commission personnel record is unavailable for the transaction adjustment.');

                $source = $rows->first(fn ($row) => (float) $row->commission_amount > 0)
                    ?: CommissionEarning::query()->where('sale_item_id', $item->id)->where('personnel_id', $personnelId)->orderBy('id')->first();
                $lockedSource = $rows->first(fn ($row) => in_array(strtoupper((string) $row->status), ['APPROVED', 'PAID'], true) && (float) $row->commission_amount > 0);
                $baseWorkDate = $expected['work_date'] ?? $source?->work_date?->toDateString() ?? $today;
                $effectBase = 'TXA:'.$request->id.':COMMISSION:'.$item->id.':'.$personnelId;

                $createAdjustment = function (float $amount, string $status, string $workDate, string $suffix, ?CommissionEarning $sourceRow) use ($actor, $sale, $item, $expected, $personnel, $personnelId, $request, $effectBase) {
                    $effectKey = $effectBase.':'.$suffix;
                    $existing = CommissionEarning::query()->where('transaction_action_effect_key', $effectKey)->first();
                    if ($existing) return $existing;

                    $now = $this->clock->now();
                    return CommissionEarning::query()->create([
                        'business_id' => $sale->business_id,
                        'code' => $this->codes->next('COMMISSION_EARNING:'.$now->format('my'), 'CME-'.$now->format('my').'-', 6, $sale->business_id),
                        'sale_id' => $sale->id,
                        'sale_item_id' => $item->id,
                        'visit_id' => $sale->visit_id,
                        'visit_service_line_id' => null,
                        'visit_retail_line_id' => null,
                        'earning_type' => 'ADJUSTMENT',
                        'booking_id' => $sale->booking_id,
                        'customer_id' => $sale->customer_id,
                        'service_id' => $expected['service_id'] ?? $sourceRow?->service_id,
                        'personnel_id' => $personnelId,
                        'commission_rule_id' => $expected['rule']?->id ?? $sourceRow?->commission_rule_id,
                        'work_date' => $workDate,
                        'service_name_snapshot' => $expected['service_name'] ?? $item->item_name_snapshot,
                        'personnel_name_snapshot' => $personnel->full_name,
                        'basis' => 'TRANSACTION_ACTION',
                        'basis_amount' => abs($amount),
                        'calculation_type' => 'ADJUSTMENT',
                        'rate_value' => 0,
                        'commission_amount' => round($amount, 2),
                        'status' => $status,
                        'source_transaction_action_request_id' => $request->id,
                        'source_commission_earning_id' => $sourceRow?->id,
                        'transaction_action_effect_key' => $effectKey,
                        'reversal_reason' => $amount < 0 ? ($request->reason_label ?: 'Transaction correction/reversal') : null,
                        'created_by' => $actor->id,
                    ]);
                };

                if ($delta > 0) {
                    // Additions that arise after a historical payroll lock belong to a future payroll.
                    // Any addition after an Approved/Paid payroll lock belongs to a future payroll.
                    // Historical payroll periods are immutable even when another unlocked adjustment exists.
                    $workDate = $lockedSource ? $today : $baseWorkDate;
                    $earning = $createAdjustment($delta, 'OPEN', $workDate, 'ADD', $source);
                    $created[] = ['id' => $earning->id, 'personnel_id' => $personnelId, 'amount' => $delta, 'sale_item_id' => $item->id, 'mode' => 'FUTURE_EARNING'];
                    $touchedDays[$personnelId.'|'.$workDate] = [$personnel, $workDate];
                    continue;
                }

                $reduction = abs($delta);
                $unlockedNet = round((float) $rows
                    ->filter(fn ($row) => in_array(strtoupper((string) $row->status), ['OPEN', 'IN_PAYROLL_DRAFT'], true))
                    ->sum('commission_amount'), 2);
                $prePayrollAmount = min($reduction, max(0, $unlockedNet));

                if ($prePayrollAmount > self::EPSILON) {
                    $earning = $createAdjustment(-$prePayrollAmount, 'OPEN', $baseWorkDate, 'PRE', $source);
                    $created[] = ['id' => $earning->id, 'personnel_id' => $personnelId, 'amount' => -$prePayrollAmount, 'sale_item_id' => $item->id, 'mode' => 'PRE_PAYROLL'];
                    $touchedDays[$personnelId.'|'.$baseWorkDate] = [$personnel, $baseWorkDate];
                }

                $remainingReduction = round($reduction - $prePayrollAmount, 2);
                if ($remainingReduction <= self::EPSILON) continue;

                if ($lockedSource) {
                    // Historical Approved/Paid payroll stays immutable. The negative earning
                    // reverses the commission accrual now, while PayrollRecovery carries the
                    // employee recovery forward until future payroll has enough payable amount.
                    $earning = $createAdjustment(-$remainingReduction, 'RECOVERY_PENDING', $today, 'RECOVERY', $lockedSource);
                    $recovery = $this->payrollRecoveries->createForLockedAdjustment($actor, $earning, $lockedSource, 'COMMISSION', $request);
                    $created[] = ['id' => $earning->id, 'personnel_id' => $personnelId, 'amount' => -$remainingReduction, 'sale_item_id' => $item->id, 'mode' => 'POST_PAYROLL_RECOVERY'];
                    if ($recovery) $recoveries[] = ['id' => $recovery->id, 'code' => $recovery->code, 'amount' => (float) $recovery->recovery_amount, 'personnel_id' => $personnelId];
                    continue;
                }

                // Safety fallback for an unlocked source that was not represented in the
                // current OPEN/IN_PAYROLL_DRAFT net. Keep it in the original earning period.
                $earning = $createAdjustment(-$remainingReduction, 'OPEN', $baseWorkDate, 'PRE_REMAINDER', $source);
                $created[] = ['id' => $earning->id, 'personnel_id' => $personnelId, 'amount' => -$remainingReduction, 'sale_item_id' => $item->id, 'mode' => 'PRE_PAYROLL'];
                $touchedDays[$personnelId.'|'.$baseWorkDate] = [$personnel, $baseWorkDate];
            }
        }

        foreach ($touchedDays as [$personnel, $date]) {
            $this->dailyCompensation->recalculatePersonnelDay($personnel, $date);
        }

        return ['adjustments_created' => $created, 'recoveries_created' => $recoveries, 'count' => count($created)];
    }

    private function correctedDiscount(SaleItem $item, float $newGross, int $newQuantity): float
    {
        $applications = is_array($item->promotion_applications ?? null) ? $item->promotion_applications : [];
        if ($applications) {
            $remaining = max(0, round($newGross, 2));
            foreach ($applications as $application) {
                $rate = max(0, (float) ($application['rate_value'] ?? 0));
                $discount = strtoupper((string) ($application['calculation_type'] ?? 'PERCENTAGE')) === 'FIXED'
                    ? $rate * max(1, $newQuantity)
                    : $remaining * ($rate / 100);
                $remaining = max(0, round($remaining - min($remaining, round($discount, 2)), 2));
            }
            return round($newGross - $remaining, 2);
        }

        if (! $item->discount_rule_id || ! $item->discount_calculation_type_snapshot) return 0.0;
        $rate = max(0, (float) ($item->discount_rate_value_snapshot ?? 0));
        $discount = strtoupper((string) $item->discount_calculation_type_snapshot) === 'FIXED'
            ? $rate * max(1, $newQuantity)
            : $newGross * ($rate / 100);
        return min($newGross, round($discount, 2));
    }

    private function activeAppliedToItem(int $saleItemId): float
    {
        return round((float) PaymentApplication::query()
            ->where('target_sale_item_id', $saleItemId)
            ->where('status', 'ACTIVE')
            ->sum('amount'), 2);
    }
}
