<?php

namespace App\Services\Inventory;

use App\Models\AccountingJournalEntry;
use App\Models\AccountingProductMapping;
use App\Models\ChartOfAccount;
use App\Models\InventoryMovement;
use App\Models\InventoryReceipt;
use App\Models\InventoryReceiptActionLine;
use App\Models\InventoryReceiptActionRefund;
use App\Models\InventoryReceiptActionRequest;
use App\Models\InventoryReceiptLine;
use App\Models\InventoryStock;
use App\Models\InventoryStockReturn;
use App\Models\RetailProduct;
use App\Models\User;
use App\Services\Accounting\AccountingJournalService;
use App\Services\Foundation\BusinessClock;
use App\Services\Foundation\CodeSequenceService;
use App\Services\Foundation\SupportingDocumentService;
use App\Services\Security\AuditService;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryReceiptActionService
{
    public function __construct(
        private readonly CodeSequenceService $codes,
        private readonly BusinessClock $clock,
        private readonly AccountingJournalService $journals,
        private readonly AuditService $audit,
        private readonly SupportingDocumentService $documents,
    ) {}

    public function requestReversal(User $actor, InventoryReceipt $receipt, string $reason, ?int $reasonReferenceId = null): InventoryReceiptActionRequest
    {
        $this->assertReceipt($actor, $receipt);
        $reason = trim($reason);
        if ($reason === '') throw new DomainException('Reversal reason is required.');

        return DB::transaction(function () use ($actor, $receipt, $reason, $reasonReferenceId) {
            $lockedReceipt = InventoryReceipt::query()
                ->where('business_id', $actor->business_id)
                ->whereKey($receipt->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (strtoupper((string) $lockedReceipt->status) !== 'POSTED') throw new DomainException('Only Posted stock receipts can be reversed.');
            if (InventoryStockReturn::query()->where('inventory_receipt_id', $lockedReceipt->id)->whereIn('status', ['PENDING','APPROVED'])->exists()) {
                throw new DomainException('This stock receipt already has a stock return workflow. Resolve the pending Stock Return or use Stock Return history instead of reversing the receipt.');
            }
            if (InventoryReceiptActionRequest::query()->where('inventory_receipt_id', $lockedReceipt->id)->where('status', 'PENDING')->exists()) {
                throw new DomainException('This stock receipt already has a pending correction or reversal request. Approve or reject it before creating another request.');
            }

            $now = $this->clock->now();
            $request = InventoryReceiptActionRequest::query()->create([
                'business_id' => $actor->business_id,
                'code' => $this->codes->next('INVENTORY_RECEIPT_REVERSAL:'.$actor->business_id.':'.$now->format('my'), 'IRR-'.$now->format('my').'-', 5, $actor->business_id),
                'inventory_receipt_id' => $lockedReceipt->id,
                'action_type' => 'REVERSAL',
                'status' => 'PENDING',
                'reason' => $reason,
                'reason_reference_id' => $reasonReferenceId,
                'requested_by' => $actor->id,
            ]);

            $this->audit->record(action:'REQUEST_STOCK_RECEIPT_REVERSAL', module:'stock-received', entityType:InventoryReceiptActionRequest::class, entityId:$request->id, after:$request->toArray(), reason:$reason, actor:$actor);
            return $this->fresh($request);
        });
    }

    public function requestCorrection(User $actor, InventoryReceipt $receipt, InventoryReceiptLine $line, float $correctedPurchaseQuantity, float $correctedPurchaseUnitCost, string $reason, ?int $reasonReferenceId = null): InventoryReceiptActionRequest
    {
        $this->assertReceipt($actor, $receipt);
        if ((int) $line->inventory_receipt_id !== (int) $receipt->id) abort(404);

        $reason = trim($reason);
        if ($reason === '') throw new DomainException('Correction reason is required.');
        $correctedPurchaseQuantity = round($correctedPurchaseQuantity, 4);
        $correctedPurchaseUnitCost = round($correctedPurchaseUnitCost, 2);
        if ($correctedPurchaseQuantity < 0) throw new DomainException('Corrected purchase quantity cannot be negative.');
        if ($correctedPurchaseUnitCost < 0) throw new DomainException('Corrected purchase unit cost cannot be negative.');

        return DB::transaction(function () use ($actor, $receipt, $line, $reason, $correctedPurchaseQuantity, $correctedPurchaseUnitCost, $reasonReferenceId) {
            $lockedReceipt = InventoryReceipt::query()
                ->where('business_id', $actor->business_id)
                ->whereKey($receipt->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (strtoupper((string) $lockedReceipt->status) !== 'POSTED') throw new DomainException('Only Posted stock receipts can be corrected.');
            if (InventoryStockReturn::query()->where('inventory_receipt_id', $lockedReceipt->id)->whereIn('status', ['PENDING','APPROVED'])->exists()) {
                throw new DomainException('Stock receipt item correction is unavailable while this receipt has a pending or approved Stock Return.');
            }
            if (InventoryReceiptActionRequest::query()->where('inventory_receipt_id', $lockedReceipt->id)->where('status', 'PENDING')->exists()) {
                throw new DomainException('This stock receipt already has a pending correction or reversal request. Approve or reject it before creating another request.');
            }

            $lockedLine = InventoryReceiptLine::query()
                ->where('inventory_receipt_id', $lockedReceipt->id)
                ->whereKey($line->id)
                ->lockForUpdate()
                ->first();
            if (! $lockedLine) abort(404);

            $state = $this->effectiveLineState($lockedLine);
            $factor = max(0.000001, (float) ($lockedLine->conversion_factor ?: 1));
            $quantityDelta = round(($correctedPurchaseQuantity - $state['purchase_quantity']) * $factor, 4);
            $newValue = round($correctedPurchaseQuantity * $correctedPurchaseUnitCost, 2);
            $valueDelta = round($newValue - $state['line_total'], 2);
            if (abs($quantityDelta) < 0.00005 && abs($valueDelta) < 0.005) throw new DomainException('The corrected quantity and cost are unchanged.');

            $now = $this->clock->now();
            $request = InventoryReceiptActionRequest::query()->create([
                'business_id' => $actor->business_id,
                'code' => $this->codes->next('INVENTORY_RECEIPT_CORRECTION:'.$actor->business_id.':'.$now->format('my'), 'IRC-'.$now->format('my').'-', 5, $actor->business_id),
                'inventory_receipt_id' => $lockedReceipt->id,
                'action_type' => 'CORRECTION',
                'status' => 'PENDING',
                'reason' => $reason,
                'reason_reference_id' => $reasonReferenceId,
                'requested_by' => $actor->id,
            ]);
            InventoryReceiptActionLine::query()->create([
                'inventory_receipt_action_request_id' => $request->id,
                'inventory_receipt_line_id' => $lockedLine->id,
                'before_purchase_quantity' => $state['purchase_quantity'],
                'before_purchase_unit_cost' => $state['purchase_unit_cost'],
                'corrected_purchase_quantity' => $correctedPurchaseQuantity,
                'corrected_purchase_unit_cost' => $correctedPurchaseUnitCost,
                'quantity_delta' => $quantityDelta,
                'value_delta' => $valueDelta,
            ]);
            $this->audit->record(action:'REQUEST_STOCK_RECEIPT_CORRECTION', module:'stock-received', entityType:InventoryReceiptActionRequest::class, entityId:$request->id, after:$request->fresh('lines')->toArray(), reason:$reason, actor:$actor);
            return $this->fresh($request);
        });
    }

    public function approve(User $actor, InventoryReceiptActionRequest $request, ?string $notes = null): InventoryReceiptActionRequest
    {
        $this->assertRequest($actor, $request);
        return DB::transaction(function () use ($actor, $request, $notes) {
            $locked = InventoryReceiptActionRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'PENDING') throw new DomainException('Only Pending stock receipt requests can be approved.');
            $receipt = InventoryReceipt::query()->whereKey($locked->inventory_receipt_id)->lockForUpdate()->firstOrFail();
            $this->assertReceipt($actor, $receipt);
            if (strtoupper((string) $receipt->status) !== 'POSTED') throw new DomainException('The stock receipt is no longer Posted.');

            if (strtoupper((string) $locked->action_type) === 'REVERSAL') {
                $this->approveReversal($actor, $locked, $receipt);
            } else {
                $this->approveCorrection($actor, $locked, $receipt);
            }

            $locked->update(['status'=>'APPROVED','reviewed_by'=>$actor->id,'reviewed_at'=>$this->clock->now(),'review_notes'=>$notes]);
            $this->audit->record(action:'APPROVE_STOCK_RECEIPT_'.strtoupper((string)$locked->action_type), module:'stock-received', entityType:InventoryReceiptActionRequest::class, entityId:$locked->id, before:['status'=>'PENDING'], after:['status'=>'APPROVED'], reason:$notes ?: $locked->reason, actor:$actor);
            return $this->fresh($locked);
        });
    }

    public function reject(User $actor, InventoryReceiptActionRequest $request, string $notes, ?int $reviewReasonReferenceId = null): InventoryReceiptActionRequest
    {
        $this->assertRequest($actor, $request);
        $notes = trim($notes);
        if ($notes === '') throw new DomainException('Rejection reason is required.');
        $request = InventoryReceiptActionRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
        if ($request->status !== 'PENDING') throw new DomainException('Only Pending stock receipt requests can be rejected.');
        $request->update(['status'=>'REJECTED','reviewed_by'=>$actor->id,'reviewed_at'=>$this->clock->now(),'review_reason_reference_id'=>$reviewReasonReferenceId,'review_notes'=>$notes]);
        $this->audit->record(action:'REJECT_STOCK_RECEIPT_'.strtoupper((string)$request->action_type), module:'stock-received', entityType:InventoryReceiptActionRequest::class, entityId:$request->id, before:['status'=>'PENDING'], after:['status'=>'REJECTED'], reason:$notes, actor:$actor);
        return $this->fresh($request);
    }

    private function approveCorrection(User $actor, InventoryReceiptActionRequest $request, InventoryReceipt $receipt): void
    {
        $lineRequest = InventoryReceiptActionLine::query()->where('inventory_receipt_action_request_id', $request->id)->lockForUpdate()->firstOrFail();
        $line = InventoryReceiptLine::query()->with('product')->whereKey($lineRequest->inventory_receipt_line_id)->lockForUpdate()->firstOrFail();
        $state = $this->effectiveLineState($line, $request->id);
        if (abs($state['purchase_quantity'] - (float)$lineRequest->before_purchase_quantity) > 0.00005 || abs($state['purchase_unit_cost'] - (float)$lineRequest->before_purchase_unit_cost) > 0.005) {
            throw new DomainException('This receipt line changed after the correction request was submitted. Reject it and create a fresh correction request.');
        }

        $qtyDelta = round((float) $lineRequest->quantity_delta, 4);
        $valueDelta = round((float) $lineRequest->value_delta, 2);
        $this->applyInventoryDelta($actor, $receipt, $line, $qtyDelta, $valueDelta, 'STOCK_RECEIPT_CORRECTION', $request->code, 'Approved correction for '.$receipt->code);

        $mapping = $this->productMapping((int)$receipt->business_id, (int)$line->retail_product_id);
        $journalLines = [];
        if ($valueDelta > 0.004) {
            $journalLines[] = ['account_id'=>$mapping->inventory_account_id,'debit'=>$valueDelta,'credit'=>0,'memo'=>'Increase inventory from approved stock receipt correction'];
            $journalLines[] = ['account_key'=>'ACCOUNTS_PAYABLE','debit'=>0,'credit'=>$valueDelta,'memo'=>'Additional supplier payable from stock receipt correction'];
        } elseif ($valueDelta < -0.004) {
            $decrease = abs($valueDelta);
            $outstandingBefore = $this->receiptOutstandingBeforeCorrection($receipt);
            $apReduction = round(min($decrease, $outstandingBefore), 2);
            $refundReceivable = round($decrease - $apReduction, 2);
            if ($apReduction > 0.004) $journalLines[] = ['account_key'=>'ACCOUNTS_PAYABLE','debit'=>$apReduction,'credit'=>0,'memo'=>'Reduce supplier payable from stock receipt correction'];
            if ($refundReceivable > 0.004) $journalLines[] = ['account_key'=>'SUPPLIER_REFUNDS_RECEIVABLE','debit'=>$refundReceivable,'credit'=>0,'memo'=>'Supplier refund due from stock receipt correction'];
            $journalLines[] = ['account_id'=>$mapping->inventory_account_id,'debit'=>0,'credit'=>$decrease,'memo'=>'Reduce inventory from approved stock receipt correction'];
        }

        if ($journalLines) {
            $journal = $this->journals->postEntry((int)$receipt->business_id, $receipt->branch_id, $this->clock->today(), 'INVENTORY_RECEIPT_ACTION', (int)$request->id, 'CORRECTION', $request->code, 'Approved stock receipt correction '.$request->code, $journalLines, $actor->id);
            if (! $journal) throw new DomainException('Unable to post the stock receipt correction journal. Verify Accounting Mapping.');
        }

        $newTotal = round((float)$receipt->total_cost + $valueDelta, 2);
        if ($newTotal < -0.004) throw new DomainException('Correction would make the stock receipt total negative.');
        $paid = round((float) collect($receipt->payment_splits ?: [])->sum(fn($row)=>(float)($row['amount']??0)), 2);
        $grand = round(max(0,$newTotal) + (float)$receipt->delivery_fee, 2);
        $paymentStatus = $paid <= 0.004 ? 'UNPAID' : ($paid + 0.01 < $grand ? 'PARTIAL' : 'PAID');
        $receipt->update(['total_cost'=>max(0,$newTotal),'payment_status'=>$paymentStatus]);
        $request->update(['supplier_refund_receivable' => $refundReceivable ?? 0]);
    }

    public function receiveRefund(User $actor, InventoryReceiptActionRequest $request, array $data): InventoryReceiptActionRequest
    {
        $this->assertRequest($actor, $request);
        if (strtoupper((string) $request->action_type) !== 'CORRECTION' || $request->status !== 'APPROVED') {
            throw new DomainException('Only Approved stock receipt corrections with a supplier refund due can receive refunds.');
        }
        $received = round((float) $request->refunds()->sum('amount'), 2);
        $remaining = round(max(0, (float) $request->supplier_refund_receivable - $received), 2);
        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($remaining <= 0.004) throw new DomainException('This stock receipt correction has no remaining Supplier Refund Receivable.');
        if ($amount <= 0) throw new DomainException('Refund amount must be greater than zero.');
        if ($amount - $remaining > 0.01) throw new DomainException('Refund amount cannot exceed the remaining Supplier Refund Receivable.');

        $account = ChartOfAccount::query()
            ->where('business_id', $actor->business_id)
            ->whereKey((int) ($data['receiving_account_id'] ?? 0))
            ->where('active', true)
            ->where('posting_type', 'POSTING')
            ->where('is_cash_account', true)
            ->first();
        if (! $account || in_array(strtoupper((string) $account->system_key), ['CASH_ON_HAND','PETTY_CASH','CASH_WITH_MANAGER','POS_CLEARING'], true)) {
            throw new DomainException('Select an actual receiving bank or e-wallet account.');
        }

        $date = (string) ($data['refund_date'] ?? $this->clock->today());
        if ($date > $this->clock->today()) throw new DomainException('Refund date cannot be in the future.');

        return DB::transaction(function () use ($actor, $request, $account, $amount, $date, $data) {
            $locked = InventoryReceiptActionRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $received = round((float) $locked->refunds()->sum('amount'), 2);
            $remaining = round(max(0, (float) $locked->supplier_refund_receivable - $received), 2);
            if ($amount - $remaining > 0.01) throw new DomainException('Refund amount cannot exceed the remaining Supplier Refund Receivable.');

            $refund = InventoryReceiptActionRefund::query()->create([
                'inventory_receipt_action_request_id' => $locked->id,
                'refund_date' => $date,
                'receiving_account_id' => $account->id,
                'amount' => $amount,
                'reference' => trim((string) ($data['reference'] ?? '')) ?: null,
                'created_by' => $actor->id,
            ]);
            $this->documents->claimTokens($actor, $data['attachment_tokens'] ?? [], 'stock-received', 'INVENTORY_RECEIPT_ACTION_REFUND', (int) $refund->id, 'SUPPLIER_REFUND_PROOF');
            $receipt = InventoryReceipt::query()->findOrFail($locked->inventory_receipt_id);
            $journal = $this->journals->postEntry(
                (int) $locked->business_id,
                $receipt->branch_id,
                $date,
                'INVENTORY_RECEIPT_ACTION_REFUND',
                (int) $refund->id,
                'RECEIVE',
                $locked->code,
                'Supplier refund received for stock receipt correction '.$locked->code,
                [
                    ['account_id'=>$account->id,'debit'=>$amount,'credit'=>0,'memo'=>'Supplier refund received'],
                    ['account_key'=>'SUPPLIER_REFUNDS_RECEIVABLE','debit'=>0,'credit'=>$amount,'memo'=>'Clear supplier refund receivable'],
                ],
                $actor->id
            );
            if (! $journal) throw new DomainException('Unable to post the supplier refund receipt journal.');
            $this->audit->record(action:'RECEIVE_STOCK_RECEIPT_CORRECTION_REFUND', module:'stock-received', entityType:InventoryReceiptActionRefund::class, entityId:$refund->id, after:$refund->toArray(), actor:$actor);
            return $this->fresh($locked);
        });
    }

    public function refundReceivingAccounts(User $actor): Collection
    {
        return ChartOfAccount::query()
            ->where('business_id', $actor->business_id)
            ->where('active', true)
            ->where('posting_type', 'POSTING')
            ->where('is_cash_account', true)
            ->where(function ($q) {
                $q->whereNull('system_key')->orWhereNotIn('system_key', ['CASH_ON_HAND','PETTY_CASH','CASH_WITH_MANAGER','POS_CLEARING']);
            })
            ->orderBy('code')
            ->get(['id','code','name']);
    }

    private function approveReversal(User $actor, InventoryReceiptActionRequest $request, InventoryReceipt $receipt): void
    {
        if (InventoryStockReturn::query()->where('inventory_receipt_id', $receipt->id)->whereIn('status',['PENDING','APPROVED'])->exists()) {
            throw new DomainException('The receipt can no longer be reversed because it has a stock return workflow.');
        }

        $receipt->loadMissing('lines.product');
        foreach ($receipt->lines as $line) {
            $state = $this->effectiveLineState($line);
            $qty = round((float)$state['quantity_received'], 4);
            if ($qty <= 0.00005) continue;
            $stock = InventoryStock::query()->where('branch_id',$receipt->branch_id)->where('retail_product_id',$line->retail_product_id)->lockForUpdate()->first();
            if (! $stock || (float)$stock->on_hand + 0.00005 < $qty) {
                throw new DomainException(($line->product?->name ?: 'An item').' no longer has enough On Hand stock to reverse this receipt. Use Stock Return or Inventory Adjustment for the real-world correction.');
            }
        }

        foreach ($receipt->lines as $line) {
            $state = $this->effectiveLineState($line);
            $qty = round((float)$state['quantity_received'], 4);
            $value = round((float)$state['line_total'], 2);
            if ($qty <= 0.00005 && $value <= 0.004) continue;
            $this->applyInventoryDelta($actor, $receipt, $line, -$qty, -$value, 'STOCK_RECEIPT_REVERSAL', $request->code, 'Reverse stock receipt '.$receipt->code);
        }

        $journalLines = $this->netReceiptAccountingToReverse($receipt);
        if ($journalLines) {
            $journal = $this->journals->postEntry((int)$receipt->business_id, $receipt->branch_id, $this->clock->today(), 'INVENTORY_RECEIPT_ACTION', (int)$request->id, 'REVERSAL', $request->code, 'Reverse stock receipt '.$receipt->code.'. '.$request->reason, $journalLines, $actor->id);
            if (! $journal) throw new DomainException('Unable to post the stock receipt reversal journal.');
        }
        $receipt->update(['status'=>'REVERSED']);
    }

    private function netReceiptAccountingToReverse(InventoryReceipt $receipt): array
    {
        $correctionIds = InventoryReceiptActionRequest::query()->where('inventory_receipt_id',$receipt->id)->where('action_type','CORRECTION')->where('status','APPROVED')->pluck('id');
        $correctionRefundIds = $correctionIds->isEmpty()
            ? collect()
            : InventoryReceiptActionRefund::query()->whereIn('inventory_receipt_action_request_id', $correctionIds)->pluck('id');
        $entries = AccountingJournalEntry::query()->where('business_id',$receipt->business_id)->where('status','POSTED')
            ->where(function($q) use($receipt,$correctionIds,$correctionRefundIds){
                $q->where(function($x) use($receipt){ $x->where('source_type','INVENTORY_RECEIPT')->where('source_id',$receipt->id); });
                if ($correctionIds->isNotEmpty()) $q->orWhere(function($x) use($correctionIds){ $x->where('source_type','INVENTORY_RECEIPT_ACTION')->whereIn('source_id',$correctionIds); });
                if ($correctionRefundIds->isNotEmpty()) $q->orWhere(function($x) use($correctionRefundIds){ $x->where('source_type','INVENTORY_RECEIPT_ACTION_REFUND')->whereIn('source_id',$correctionRefundIds); });
            })->with('lines')->get();
        $net = [];
        foreach ($entries as $entry) foreach ($entry->lines as $line) {
            $id = (int)$line->account_id;
            if (!$id) continue;
            $net[$id] = round(($net[$id] ?? 0) + (float)$line->debit - (float)$line->credit, 2);
        }
        $lines=[];
        foreach ($net as $accountId=>$balance) {
            if (abs($balance) <= 0.004) continue;
            $lines[] = $balance > 0
                ? ['account_id'=>$accountId,'debit'=>0,'credit'=>$balance,'memo'=>'Reverse net stock receipt accounting']
                : ['account_id'=>$accountId,'debit'=>abs($balance),'credit'=>0,'memo'=>'Reverse net stock receipt accounting'];
        }
        return $lines;
    }

    private function applyInventoryDelta(User $actor, InventoryReceipt $receipt, InventoryReceiptLine $line, float $qtyDelta, float $valueDelta, string $movementType, string $reference, string $reason): void
    {
        $product = RetailProduct::query()->where('business_id',$actor->business_id)->whereKey($line->retail_product_id)->lockForUpdate()->firstOrFail();
        $stock = InventoryStock::query()->firstOrCreate(['branch_id'=>$receipt->branch_id,'retail_product_id'=>$product->id], ['business_id'=>$actor->business_id,'on_hand'=>0,'reserved'=>0]);
        $stock = InventoryStock::query()->whereKey($stock->id)->lockForUpdate()->firstOrFail();
        $before = round((float)$stock->on_hand, 4);
        $after = round($before + $qtyDelta, 4);
        if ($after < -0.00005) throw new DomainException($product->name.' does not have enough On Hand stock for this action.');
        $stock->update(['on_hand'=>max(0,$after)]);

        $globalQtyBefore = round((float)InventoryStock::query()->where('business_id',$actor->business_id)->where('retail_product_id',$product->id)->sum('on_hand') - $qtyDelta, 4);
        $currentValue = round(max(0,$globalQtyBefore) * (float)$product->unit_cost, 4);
        $globalQtyAfter = round($globalQtyBefore + $qtyDelta, 4);
        $newValue = round($currentValue + $valueDelta, 4);
        if ($globalQtyAfter > 0.00005 && $newValue >= -0.0001) {
            $product->update(['unit_cost'=>round(max(0,$newValue)/$globalQtyAfter,4),'updated_by'=>$actor->id]);
        }

        InventoryMovement::query()->create([
            'business_id'=>$actor->business_id,'branch_id'=>$receipt->branch_id,'retail_product_id'=>$product->id,
            'movement_type'=>$movementType,'quantity_change'=>$qtyDelta,'quantity_before'=>$before,'quantity_after'=>max(0,$after),
            'unit_cost_snapshot'=>abs($qtyDelta)>0.00005 ? round(abs($valueDelta/$qtyDelta),4) : (float)$product->unit_cost,
            'reference_code'=>$reference,'reason'=>$reason,'created_by'=>$actor->id,'movement_at'=>$this->clock->now(),
        ]);
    }

    public function effectiveLineState(InventoryReceiptLine $line, ?int $excludeRequestId = null): array
    {
        $purchaseQty = (float)($line->purchase_quantity ?? $line->quantity_received);
        $purchaseCost = (float)($line->purchase_unit_cost ?? ((float)$line->unit_cost * (float)($line->conversion_factor ?: 1)));
        $approved = InventoryReceiptActionLine::query()
            ->join('inventory_receipt_action_requests as r','r.id','=','inventory_receipt_action_lines.inventory_receipt_action_request_id')
            ->where('inventory_receipt_action_lines.inventory_receipt_line_id',$line->id)
            ->where('r.action_type','CORRECTION')->where('r.status','APPROVED')
            ->when($excludeRequestId, fn($q)=>$q->where('r.id','<>',$excludeRequestId))
            ->orderBy('r.reviewed_at')->orderBy('r.id')
            ->get(['inventory_receipt_action_lines.corrected_purchase_quantity','inventory_receipt_action_lines.corrected_purchase_unit_cost']);
        foreach ($approved as $row) {
            $purchaseQty = (float)$row->corrected_purchase_quantity;
            $purchaseCost = (float)$row->corrected_purchase_unit_cost;
        }
        $factor = max(0.000001,(float)($line->conversion_factor ?: 1));
        return [
            'purchase_quantity'=>round($purchaseQty,4),
            'purchase_unit_cost'=>round($purchaseCost,2),
            'quantity_received'=>round($purchaseQty*$factor,4),
            'unit_cost'=>round($purchaseCost/$factor,4),
            'line_total'=>round($purchaseQty*$purchaseCost,2),
        ];
    }

    private function receiptOutstandingBeforeCorrection(InventoryReceipt $receipt): float
    {
        $grand = round((float)$receipt->total_cost + (float)$receipt->delivery_fee, 2);
        $paid = round((float)collect($receipt->payment_splits ?: [])->sum(fn($row)=>(float)($row['amount']??0)),2);
        $apCredits = round((float)InventoryStockReturn::query()->where('inventory_receipt_id',$receipt->id)->where('status','APPROVED')->sum('ap_credit_amount'),2);
        return round(max(0,$grand-$paid-$apCredits),2);
    }

    private function productMapping(int $businessId, int $productId): AccountingProductMapping
    {
        $mapping = AccountingProductMapping::query()->where('business_id',$businessId)->where('retail_product_id',$productId)->first();
        if (! $mapping || ! $mapping->inventory_account_id) throw new DomainException('Accounting Mapping is incomplete for this inventory item.');
        return $mapping;
    }

    private function fresh(InventoryReceiptActionRequest $request): InventoryReceiptActionRequest
    {
        return $request->fresh(['receipt.branch','receipt.supplier','lines.receiptLine.product','requester','reviewer','reasonReference','reviewReasonReference','refunds.receivingAccount','refunds.creator']);
    }

    private function assertReceipt(User $actor, InventoryReceipt $receipt): void
    {
        if ((int)$receipt->business_id !== (int)$actor->business_id) abort(404);
    }
    private function assertRequest(User $actor, InventoryReceiptActionRequest $request): void
    {
        if ((int)$request->business_id !== (int)$actor->business_id) abort(404);
    }
}
