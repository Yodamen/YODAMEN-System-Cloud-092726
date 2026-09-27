<?php

namespace App\Services\Sales;

use App\Models\Payment;
use App\Models\Sale;
use App\Models\ServicePrice;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitServiceLine;
use App\Services\Foundation\BusinessClock;
use App\Services\Foundation\CodeSequenceService;
use App\Services\Security\AuditService;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SaleDraftService
{
    public function __construct(
        private readonly CodeSequenceService $codes,
        private readonly BusinessClock $clock,
        private readonly AuditService $audit,
        private readonly PaymentLedgerService $paymentLedger,
    ) {
    }

    public function ensureForCompletedVisit(User $actor, Visit $visit): Sale
    {
        if ($visit->business_id !== $actor->business_id) abort(404);

        return DB::transaction(function () use ($actor, $visit) {
            $visit = Visit::query()->whereKey($visit->id)->lockForUpdate()->firstOrFail();
            if ($visit->business_id !== $actor->business_id) abort(404);

            $existing = Sale::query()->where('visit_id', $visit->id)->first();
            if ($existing) {
                return $this->loadSale($existing);
            }

            if (strtoupper((string) $visit->status) !== 'COMPLETED') {
                throw new DomainException('A Sale draft can only be created from a completed Visit.');
            }

            $visit->load(['serviceLines.service', 'retailLines.assignedPersonnel', 'retailLines.retailProduct', 'booking', 'customer']);
            $completedServices = $visit->serviceLines
                ->filter(fn (VisitServiceLine $line) => strtoupper((string) $line->status) === 'COMPLETED')
                ->values();

            $now = $this->clock->now();
            $sale = Sale::query()->create([
                'business_id' => $visit->business_id,
                'branch_id' => $visit->branch_id,
                'code' => $this->codes->next('SALE:'.$now->format('my'), 'SAL-'.$now->format('my').'-', 6, $visit->business_id),
                'visit_id' => $visit->id,
                'booking_id' => $visit->booking_id,
                'customer_id' => $visit->customer_id,
                'sale_date' => $now,
                'gross_amount' => 0,
                'discount_amount' => 0,
                'net_amount' => 0,
                'discount_code_snapshot' => null,
                'advance_payments' => 0,
                'checkout_payments' => 0,
                'total_paid' => 0,
                'applied_amount' => 0,
                'outstanding_balance' => 0,
                'status' => 'FOR_CHECKOUT',
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $sortOrder = 10;
            $gross = 0.0;

            foreach ($completedServices as $line) {
                $source = strtoupper((string) ($line->source_type ?: 'NEW_SERVICE'));
                $itemType = match ($source) {
                    'SERVICE_CREDIT' => 'SERVICE_CREDIT_USE',
                    'NEW_PACKAGE' => 'PACKAGE',
                    default => 'SERVICE',
                };

                $unitPrice = $itemType === 'SERVICE_CREDIT_USE'
                    ? 0.0
                    : $this->servicePrice($visit, $line);
                $quantity = $itemType === 'PACKAGE' ? 1 : max(1, (int) $line->quantity);
                $lineTotal = round($unitPrice * $quantity, 2);
                $gross += $lineTotal;

                $sale->items()->create([
                    'code' => $sale->code.'-I'.str_pad((string) ($sale->items()->count() + 1), 3, '0', STR_PAD_LEFT),
                    'source_key' => 'VISIT_SERVICE:'.$line->id,
                    'visit_service_line_id' => $line->id,
                    'service_id' => $line->service_id,
                    'assigned_personnel_id' => $line->assigned_personnel_id,
                    'assigned_personnel_name_snapshot' => $line->assigned_personnel_name_snapshot,
                    'item_type' => $itemType,
                    'item_name_snapshot' => $line->service_name_snapshot,
                    'service_source' => $source,
                    'source_reference_code' => $line->source_reference_code,
                    'package_enrollment_id' => $line->package_enrollment_id,
                    'service_credit_id' => $line->service_credit_id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                    'discount_rule_id' => null,
                    'discount_code_snapshot' => null,
                    'discount_rule_name_snapshot' => null,
                    'discount_calculation_type_snapshot' => null,
                    'discount_rate_value_snapshot' => null,
                    'discount_amount' => 0,
                    'net_line_total' => $lineTotal,
                    'effective_net_line_total' => $lineTotal,
                    'applied_amount' => 0,
                    'balance_amount' => $lineTotal,
                    'status' => 'FOR_CHECKOUT',
                    'sort_order' => $sortOrder,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);
                $sortOrder += 10;
            }

            foreach ($visit->retailLines as $line) {
                $quantity = max(1, (int) $line->quantity);
                $unitPrice = round((float) $line->unit_price_snapshot, 2);
                $lineTotal = round($unitPrice * $quantity, 2);
                $gross += $lineTotal;

                $sale->items()->create([
                    'code' => $sale->code.'-I'.str_pad((string) ($sale->items()->count() + 1), 3, '0', STR_PAD_LEFT),
                    'source_key' => 'VISIT_RETAIL:'.$line->id,
                    'visit_retail_line_id' => $line->id,
                    'retail_product_id' => $line->retail_product_id,
                    'sku_snapshot' => $line->sku_snapshot ?: $line->retailProduct?->sku,
                    'unit_cost_snapshot' => $line->retailProduct ? round((float) $line->retailProduct->unit_cost * (float) ($line->retailProduct->selling_to_basic_factor ?: 1), 2) : null,
                    'selling_uom_snapshot' => $line->retailProduct?->selling_uom ?: $line->retailProduct?->unit,
                    'selling_to_basic_factor_snapshot' => (float) ($line->retailProduct?->selling_to_basic_factor ?: 1),
                    'basic_quantity_snapshot' => $line->retailProduct ? round($quantity * (float) ($line->retailProduct->selling_to_basic_factor ?: 1), 4) : $quantity,
                    'item_type' => 'PRODUCT',
                    'assigned_personnel_id' => $line->assigned_personnel_id,
                    'assigned_personnel_name_snapshot' => $line->assigned_personnel_name_snapshot ?: ($line->assignedPersonnel ? trim($line->assignedPersonnel->first_name.' '.$line->assignedPersonnel->last_name) : null),
                    'item_name_snapshot' => $line->item_name_snapshot,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                    'discount_rule_id' => null,
                    'discount_code_snapshot' => null,
                    'discount_rule_name_snapshot' => null,
                    'discount_calculation_type_snapshot' => null,
                    'discount_rate_value_snapshot' => null,
                    'discount_amount' => 0,
                    'net_line_total' => $lineTotal,
                    'effective_net_line_total' => $lineTotal,
                    'applied_amount' => 0,
                    'balance_amount' => $lineTotal,
                    'status' => 'FOR_CHECKOUT',
                    'sort_order' => $sortOrder,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);
                $sortOrder += 10;
            }

            Payment::query()
                ->where('business_id', $visit->business_id)
                ->where('booking_id', $visit->booking_id)
                ->where('payment_type', 'ADVANCE')
                ->where('status', 'RECEIVED')
                ->whereNull('sale_id')
                ->lockForUpdate()
                ->update(['sale_id' => $sale->id, 'visit_id' => $visit->id]);

            $advance = $this->paymentLedger->advanceAvailableForBooking($visit->booking);
            $gross = round($gross, 2);
            $totalPaid = $advance;
            $balance = max(0, round($gross - $totalPaid, 2));
            $status = $gross > 0.009 && $totalPaid + 0.009 >= $gross
                ? 'AWAITING_CONFIRM'
                : ($totalPaid > 0.009 ? 'PARTIALLY_PAID' : 'FOR_CHECKOUT');

            $sale->update([
                'gross_amount' => $gross,
                'discount_amount' => 0,
                'net_amount' => $gross,
                'effective_net_amount' => $gross,
                'discount_code_snapshot' => null,
                'advance_payments' => $advance,
                'checkout_payments' => 0,
                'total_paid' => $totalPaid,
                'outstanding_balance' => $balance,
                'status' => $status,
                'updated_by' => $actor->id,
            ]);

            $sale = $this->loadSale($sale->fresh());
            $this->audit->record(
                action: 'CREATE_SALE_DRAFT',
                module: 'sales',
                entityType: Sale::class,
                entityId: $sale->id,
                after: $this->snapshot($sale),
                actor: $actor,
                metadata: [
                    'visit_id' => $visit->id,
                    'visit_code' => $visit->code,
                    'booking_id' => $visit->booking_id,
                    'financial_finalized' => false,
                    'commission_created' => false,
                ],
            );

            return $sale;
        });
    }

    public function syncCompletedVisits(User $actor): Collection
    {
        $visits = Visit::query()
            ->where('business_id', $actor->business_id)
            ->where('status', 'COMPLETED')
            ->whereDoesntHave('sale')
            ->orderBy('id')
            ->get();

        return $visits->map(fn (Visit $visit) => $this->ensureForCompletedVisit($actor, $visit));
    }

    public function snapshot(Sale $sale): array
    {
        return [
            'id' => $sale->id,
            'code' => $sale->code,
            'visit_id' => $sale->visit_id,
            'booking_id' => $sale->booking_id,
            'customer_id' => $sale->customer_id,
            'gross_amount' => (float) $sale->gross_amount,
            'discount_amount' => (float) ($sale->discount_amount ?? 0),
            'net_amount' => (float) ($sale->net_amount ?? $sale->gross_amount),
            'discount_code' => $sale->discount_code_snapshot,
            'advance_payments' => (float) $sale->advance_payments,
            'total_paid' => (float) $sale->total_paid,
            'outstanding_balance' => (float) $sale->outstanding_balance,
            'status' => $sale->status,
            'items' => $sale->items->map(fn ($item) => [
                'code' => $item->code,
                'item_type' => $item->item_type,
                'item_name' => $item->item_name_snapshot,
                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->line_total,
                'discount_amount' => (float) ($item->discount_amount ?? 0),
                'net_line_total' => (float) ($item->net_line_total ?? $item->line_total),
                'status' => $item->status,
            ])->values()->all(),
        ];
    }

    private function servicePrice(Visit $visit, VisitServiceLine $line): float
    {
        $date = $visit->visit_date?->format('Y-m-d') ?: $this->clock->today();
        $price = ServicePrice::query()
            ->where('service_id', $line->service_id)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date))
            ->where(fn ($query) => $query->where('branch_id', $visit->branch_id)->orWhereNull('branch_id'))
            ->orderByRaw('branch_id is null')
            ->orderByDesc('effective_from')
            ->first();

        return round((float) ($price?->standard_price ?? 0), 2);
    }

    private function receivedPayments(Sale $sale, string $type): float
    {
        return round((float) Payment::query()
            ->where('sale_id', $sale->id)
            ->where('payment_type', strtoupper($type))
            ->where('status', 'RECEIVED')
            ->sum('amount'), 2);
    }

    private function loadSale(Sale $sale): Sale
    {
        return $sale->load(['customer', 'booking', 'visit', 'branch', 'items.assignedPersonnel', 'payments']);
    }
}
