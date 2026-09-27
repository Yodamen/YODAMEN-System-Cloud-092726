<?php

namespace App\Services\Inventory;

use App\Models\AccountingProductMapping;
use App\Models\ChartOfAccount;
use App\Models\InventoryMovement;
use App\Models\InventoryReceipt;
use App\Models\InventoryReceiptLine;
use App\Models\InventoryReceiptActionRequest;
use App\Models\InventoryStock;
use App\Models\InventoryStockReturn;
use App\Models\InventoryStockReturnLine;
use App\Models\InventoryStockReturnRefund;
use App\Models\RetailProduct;
use App\Models\User;
use App\Services\Accounting\AccountingJournalService;
use App\Services\Foundation\BusinessClock;
use App\Services\Foundation\CodeSequenceService;
use App\Services\Foundation\SupportingDocumentService;
use App\Services\Security\AuditService;
use DomainException;
use Illuminate\Support\Facades\DB;

class StockReturnService
{
    public function __construct(
        private readonly CodeSequenceService $codes,
        private readonly BusinessClock $clock,
        private readonly AccountingJournalService $journals,
        private readonly AuditService $audit,
        private readonly InventoryReceiptActionService $receiptActions,
        private readonly SupportingDocumentService $documents,
    ) {}

    public function index(User $actor, array $filters = []): array
    {
        $query = InventoryStockReturn::query()->where('business_id',$actor->business_id)
            ->with(['branch','supplier','receipt','requester','reviewer','reasonReference','reviewReasonReference','lines.product','refunds.receivingAccount','refunds.creator'])
            ->orderByDesc('return_date')->orderByDesc('id');
        if (!empty($filters['branch_id'])) $query->where('branch_id',(int)$filters['branch_id']);
        elseif ($actor->default_branch_id) $query->where('branch_id',$actor->default_branch_id);
        if (!empty($filters['status']) && strtoupper((string)$filters['status']) !== 'ALL') $query->where('status',strtoupper((string)$filters['status']));
        if (!empty($filters['from'])) $query->whereDate('return_date','>=',$filters['from']);
        if (!empty($filters['to'])) $query->whereDate('return_date','<=',$filters['to']);
        if (!empty($filters['search'])) {
            $term=trim((string)$filters['search']);
            $query->where(function($q) use($term){$q->where('code','like','%'.$term.'%')->orWhere('reason','like','%'.$term.'%')->orWhereHas('receipt',fn($r)=>$r->where('code','like','%'.$term.'%'))->orWhereHas('supplier',fn($s)=>$s->where('company_name','like','%'.$term.'%')->orWhere('code_name','like','%'.$term.'%'));});
        }
        return ['options'=>$this->options($actor),'returns'=>$query->limit(300)->get()->map(fn($row)=>$this->serialize($row))->values()];
    }

    public function create(User $actor, array $data): InventoryStockReturn
    {
        $receiptId = (int) ($data['inventory_receipt_id'] ?? 0);
        if ($receiptId <= 0) throw new DomainException('Select a valid Posted stock receipt.');

        return DB::transaction(function () use ($actor, $data, $receiptId) {
            $receipt = InventoryReceipt::query()
                ->where('business_id', $actor->business_id)
                ->whereKey($receiptId)
                ->lockForUpdate()
                ->with(['lines.product', 'supplier'])
                ->first();

            if (! $receipt || strtoupper((string) $receipt->status) !== 'POSTED') {
                throw new DomainException('Select a valid Posted stock receipt.');
            }

            if (InventoryReceiptActionRequest::query()
                ->where('inventory_receipt_id', $receipt->id)
                ->where('status', 'PENDING')
                ->exists()) {
                throw new DomainException('Resolve the pending Stock Received correction or reversal before creating a Stock Return.');
            }

            if (InventoryStockReturn::query()
                ->where('inventory_receipt_id', $receipt->id)
                ->where('status', 'PENDING')
                ->exists()) {
                throw new DomainException('This stock receipt already has a pending Stock Return request. Approve or reject it before creating another request.');
            }

            $branchId = (int) ($data['branch_id'] ?? $receipt->branch_id);
            if ($branchId !== (int) $receipt->branch_id) throw new DomainException('Stock Return must use the same branch as the original stock receipt.');

            $returnDate = (string) ($data['return_date'] ?? '');
            if (! $returnDate) throw new DomainException('Return date is required.');
            if ($returnDate > $this->clock->today()) throw new DomainException('Return date cannot be in the future.');
            if ($receipt->received_date && $returnDate < $receipt->received_date->toDateString()) throw new DomainException('Return date cannot be earlier than the original stock receipt date.');

            $reason = trim((string) ($data['reason'] ?? ''));
            if ($reason === '') throw new DomainException('Stock return reason is required.');

            $input = collect($data['lines'] ?? [])->filter(fn ($row) => (float) ($row['quantity_returned'] ?? 0) > 0)->values();
            if ($input->isEmpty()) throw new DomainException('Add at least one item quantity to return.');
            if ($input->pluck('inventory_receipt_line_id')->map(fn ($v) => (int) $v)->duplicates()->isNotEmpty()) throw new DomainException('Each receipt item can appear only once per Stock Return.');

            $now = $this->clock->now();
            $row = InventoryStockReturn::query()->create([
                'business_id' => $actor->business_id,
                'code' => $this->codes->next('INVENTORY_STOCK_RETURN:'.$actor->business_id.':'.$now->format('my'), 'SR-'.$now->format('my').'-', 5, $actor->business_id),
                'branch_id' => $branchId,
                'supplier_id' => $receipt->supplier_id,
                'inventory_receipt_id' => $receipt->id,
                'return_date' => $returnDate,
                'reason' => $reason,
                'reason_reference_id' => $data['reason_reference_id'] ?? null,
                'status' => 'PENDING',
                'requested_by' => $actor->id,
            ]);

            $total = 0.0;
            foreach ($input as $item) {
                $line = $receipt->lines->firstWhere('id', (int) $item['inventory_receipt_line_id']);
                if (! $line) throw new DomainException('One or more selected items do not belong to the original stock receipt.');

                $state = $this->receiptActions->effectiveLineState($line);
                $already = round((float) InventoryStockReturnLine::query()
                    ->join('inventory_stock_returns as r', 'r.id', '=', 'inventory_stock_return_lines.inventory_stock_return_id')
                    ->where('inventory_stock_return_lines.inventory_receipt_line_id', $line->id)
                    ->where('r.status', 'APPROVED')
                    ->sum('inventory_stock_return_lines.quantity_returned'), 4);
                $available = round(max(0, $state['quantity_received'] - $already), 4);
                $qty = round((float) $item['quantity_returned'], 4);
                if ($qty <= 0 || $qty - $available > 0.00005) throw new DomainException(($line->product?->name ?: 'Item').' return quantity exceeds the remaining received quantity.');

                $lineTotal = round($qty * (float) $state['unit_cost'], 2);
                InventoryStockReturnLine::query()->create([
                    'inventory_stock_return_id' => $row->id,
                    'inventory_receipt_line_id' => $line->id,
                    'retail_product_id' => $line->retail_product_id,
                    'quantity_returned' => $qty,
                    'unit_cost_snapshot' => $state['unit_cost'],
                    'line_total' => $lineTotal,
                ]);
                $total += $lineTotal;
            }

            $row->update(['total_value' => round($total, 2)]);
            $this->audit->record(action:'REQUEST_STOCK_RETURN', module:'stock-returns', entityType:InventoryStockReturn::class, entityId:$row->id, after:$row->fresh('lines')->toArray(), reason:$reason, actor:$actor);
            return $row->fresh(['branch','supplier','receipt','requester','reviewer','reasonReference','reviewReasonReference','lines.product','refunds.receivingAccount','refunds.creator']);
        });
    }

    public function approve(User $actor, InventoryStockReturn $row, ?string $notes=null): InventoryStockReturn
    {
        $this->assertOwned($actor,$row);
        return DB::transaction(function() use($actor,$row,$notes){
            $locked=InventoryStockReturn::query()->whereKey($row->id)->lockForUpdate()->with(['lines.product','receipt'])->firstOrFail();
            if($locked->status!=='PENDING') throw new DomainException('Only Pending Stock Returns can be approved.');
            if(!$locked->receipt || strtoupper((string)$locked->receipt->status)!=='POSTED') throw new DomainException('The original stock receipt is no longer eligible for Stock Return.');
            if (InventoryReceiptActionRequest::query()->where('inventory_receipt_id',$locked->receipt->id)->where('status','PENDING')->exists()) throw new DomainException('Resolve the pending Stock Received correction or reversal before approving this Stock Return.');
            foreach($locked->lines as $line){
                $receiptLine = InventoryReceiptLine::query()->whereKey($line->inventory_receipt_line_id)->firstOrFail();
                $state = $this->receiptActions->effectiveLineState($receiptLine);
                $alreadyReturned = round((float) InventoryStockReturnLine::query()
                    ->join('inventory_stock_returns as r','r.id','=','inventory_stock_return_lines.inventory_stock_return_id')
                    ->where('inventory_stock_return_lines.inventory_receipt_line_id',$line->inventory_receipt_line_id)
                    ->where('r.status','APPROVED')
                    ->where('r.id','<>',$locked->id)
                    ->sum('inventory_stock_return_lines.quantity_returned'),4);
                $remainingFromReceipt = round(max(0,(float)$state['quantity_received']-$alreadyReturned),4);
                if ((float)$line->quantity_returned - $remainingFromReceipt > 0.00005) throw new DomainException(($line->product?->name?:'An item').' return quantity now exceeds the remaining quantity from the original receipt. Reject this return and create a fresh request.');
                if (abs((float)$line->unit_cost_snapshot - (float)$state['unit_cost']) > 0.0001) throw new DomainException(($line->product?->name?:'An item').' receipt cost changed after this Stock Return was requested. Reject it and create a fresh Stock Return.');
                $stock=InventoryStock::query()->where('branch_id',$locked->branch_id)->where('retail_product_id',$line->retail_product_id)->lockForUpdate()->first();
                if(!$stock || (float)$stock->on_hand+0.00005<(float)$line->quantity_returned) throw new DomainException(($line->product?->name?:'An item').' does not have enough On Hand stock to approve this return.');
            }
            $outstanding=$this->receiptOutstanding($locked->receipt);
            $total=round((float)$locked->lines->sum('line_total'),2);
            $apCredit=round(min($total,$outstanding),2);
            $refundDue=round($total-$apCredit,2);
            $credits=[];
            foreach($locked->lines as $line){
                $this->applyReturnStock($actor,$locked,$line);
                $mapping=$this->productMapping((int)$locked->business_id,(int)$line->retail_product_id);
                $credits[$mapping->inventory_account_id]=round(($credits[$mapping->inventory_account_id]??0)+(float)$line->line_total,2);
            }
            $journalLines=[];
            if($apCredit>0.004) $journalLines[]=['account_key'=>'ACCOUNTS_PAYABLE','debit'=>$apCredit,'credit'=>0,'memo'=>'Supplier credit applied to Accounts Payable'];
            if($refundDue>0.004) $journalLines[]=['account_key'=>'SUPPLIER_REFUNDS_RECEIVABLE','debit'=>$refundDue,'credit'=>0,'memo'=>'Supplier refund receivable from stock return'];
            foreach($credits as $accountId=>$amount) $journalLines[]=['account_id'=>$accountId,'debit'=>0,'credit'=>$amount,'memo'=>'Inventory returned to supplier'];
            $journal=$this->journals->postEntry((int)$locked->business_id,$locked->branch_id,$locked->return_date?->toDateString()?:$this->clock->today(),'INVENTORY_STOCK_RETURN',(int)$locked->id,'APPROVE',$locked->code,'Approved Stock Return '.$locked->code,$journalLines,$actor->id);
            if(!$journal) throw new DomainException('Unable to post the Stock Return journal. Verify Accounting Mapping.');
            $locked->update(['status'=>'APPROVED','total_value'=>$total,'ap_credit_amount'=>$apCredit,'supplier_refund_receivable'=>$refundDue,'reviewed_by'=>$actor->id,'reviewed_at'=>$this->clock->now(),'review_notes'=>$notes]);
            $this->audit->record(action:'APPROVE_STOCK_RETURN',module:'stock-returns',entityType:InventoryStockReturn::class,entityId:$locked->id,before:['status'=>'PENDING'],after:['status'=>'APPROVED','ap_credit_amount'=>$apCredit,'supplier_refund_receivable'=>$refundDue],reason:$notes?:$locked->reason,actor:$actor);
            return $locked->fresh(['branch','supplier','receipt','requester','reviewer','reasonReference','reviewReasonReference','lines.product','refunds.receivingAccount','refunds.creator']);
        });
    }

    public function reject(User $actor, InventoryStockReturn $row, string $notes, ?int $reviewReasonReferenceId = null): InventoryStockReturn
    {
        $this->assertOwned($actor,$row); $notes=trim($notes); if($notes==='') throw new DomainException('Rejection reason is required.');
        if($row->status!=='PENDING') throw new DomainException('Only Pending Stock Returns can be rejected.');
        $row->update(['status'=>'REJECTED','reviewed_by'=>$actor->id,'reviewed_at'=>$this->clock->now(),'review_reason_reference_id'=>$reviewReasonReferenceId,'review_notes'=>$notes]);
        $this->audit->record(action:'REJECT_STOCK_RETURN',module:'stock-returns',entityType:InventoryStockReturn::class,entityId:$row->id,before:['status'=>'PENDING'],after:['status'=>'REJECTED'],reason:$notes,actor:$actor);
        return $row->fresh(['branch','supplier','receipt','requester','reviewer','reasonReference','reviewReasonReference','lines.product','refunds.receivingAccount','refunds.creator']);
    }

    public function receiveRefund(User $actor, InventoryStockReturn $row, array $data): InventoryStockReturn
    {
        $this->assertOwned($actor,$row);
        if($row->status!=='APPROVED') throw new DomainException('Only Approved Stock Returns can receive supplier refunds.');
        $received=round((float)$row->refunds()->sum('amount'),2);
        $remaining=round(max(0,(float)$row->supplier_refund_receivable-$received),2);
        $amount=round((float)($data['amount']??0),2);
        if($amount<=0) throw new DomainException('Refund amount must be greater than zero.');
        if($amount-$remaining>0.01) throw new DomainException('Refund amount cannot exceed the remaining Supplier Refund Receivable.');
        $account=ChartOfAccount::query()->where('business_id',$actor->business_id)->whereKey((int)($data['receiving_account_id']??0))->where('active',true)->where('posting_type','POSTING')->where('is_cash_account',true)->first();
        if(!$account || in_array(strtoupper((string)$account->system_key),['CASH_ON_HAND','PETTY_CASH','CASH_WITH_MANAGER','POS_CLEARING'],true)) throw new DomainException('Select an actual receiving bank or e-wallet account.');
        $date=(string)($data['refund_date']??$this->clock->today());
        if($date>$this->clock->today()) throw new DomainException('Refund date cannot be in the future.');
        if($row->return_date && $date<$row->return_date->toDateString()) throw new DomainException('Refund date cannot be earlier than the Stock Return date.');
        return DB::transaction(function() use($actor,$row,$amount,$account,$date,$data){
            $locked=InventoryStockReturn::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
            if($locked->status!=='APPROVED') throw new DomainException('Only Approved Stock Returns can receive supplier refunds.');
            $received=round((float)$locked->refunds()->sum('amount'),2);
            $remaining=round(max(0,(float)$locked->supplier_refund_receivable-$received),2);
            if($remaining<=0.004) throw new DomainException('This Stock Return has no remaining Supplier Refund Receivable.');
            if($amount-$remaining>0.01) throw new DomainException('Refund amount cannot exceed the remaining Supplier Refund Receivable.');
            $refund=InventoryStockReturnRefund::query()->create(['inventory_stock_return_id'=>$locked->id,'refund_date'=>$date,'receiving_account_id'=>$account->id,'amount'=>$amount,'reference'=>trim((string)($data['reference']??''))?:null,'created_by'=>$actor->id]);
            $this->documents->claimTokens($actor, $data['attachment_tokens'] ?? [], 'stock-returns', 'INVENTORY_STOCK_RETURN_REFUND', (int) $refund->id, 'SUPPLIER_REFUND_PROOF');
            $journal=$this->journals->postEntry((int)$locked->business_id,$locked->branch_id,$date,'INVENTORY_STOCK_RETURN_REFUND',(int)$refund->id,'RECEIVE',$locked->code,'Supplier refund received for '.$locked->code,[['account_id'=>$account->id,'debit'=>$amount,'credit'=>0,'memo'=>'Supplier refund received'],['account_key'=>'SUPPLIER_REFUNDS_RECEIVABLE','debit'=>0,'credit'=>$amount,'memo'=>'Clear supplier refund receivable']],$actor->id);
            if(!$journal) throw new DomainException('Unable to post the supplier refund receipt journal.');
            $this->audit->record(action:'RECEIVE_STOCK_RETURN_REFUND',module:'stock-returns',entityType:InventoryStockReturnRefund::class,entityId:$refund->id,after:$refund->toArray(),actor:$actor);
            return $locked->fresh(['branch','supplier','receipt','requester','reviewer','reasonReference','reviewReasonReference','lines.product','refunds.receivingAccount','refunds.creator']);
        });
    }

    public function serialize(InventoryStockReturn $row): array
    {
        $row->loadMissing(['branch','supplier','receipt','requester','reviewer','reasonReference','reviewReasonReference','lines.product','refunds.receivingAccount','refunds.creator']);
        $refundReceived=round((float)$row->refunds->sum('amount'),2);
        return ['id'=>$row->id,'code'=>$row->code,'return_date'=>$row->return_date?->toDateString(),'reason'=>$row->reason,'reason_category'=>$row->reasonReference?['id'=>(int)$row->reasonReference->id,'code'=>$row->reasonReference->code,'name'=>$row->reasonReference->display_value]:null,'review_reason_category'=>$row->reviewReasonReference?['id'=>(int)$row->reviewReasonReference->id,'code'=>$row->reviewReasonReference->code,'name'=>$row->reviewReasonReference->display_value]:null,'status'=>$row->status,'total_value'=>(float)$row->total_value,'ap_credit_amount'=>(float)$row->ap_credit_amount,'supplier_refund_receivable'=>(float)$row->supplier_refund_receivable,'refund_received'=>$refundReceived,'refund_remaining'=>round(max(0,(float)$row->supplier_refund_receivable-$refundReceived),2),'branch'=>['id'=>$row->branch?->id,'name'=>$row->branch?->name],'supplier'=>['id'=>$row->supplier?->id,'name'=>$row->supplier?->code_name?:$row->supplier?->company_name],'receipt'=>['id'=>$row->receipt?->id,'code'=>$row->receipt?->code],'requested_by'=>$row->requester?->display_name,'reviewed_by'=>$row->reviewer?->display_name,'reviewed_at'=>$row->reviewed_at?->toIso8601String(),'review_notes'=>$row->review_notes,'lines'=>$row->lines->map(fn($l)=>['id'=>$l->id,'inventory_receipt_line_id'=>$l->inventory_receipt_line_id,'retail_product_id'=>$l->retail_product_id,'name'=>$l->product?->name,'sku'=>$l->product?->sku,'unit'=>$l->product?->unit,'quantity_returned'=>(float)$l->quantity_returned,'unit_cost_snapshot'=>(float)$l->unit_cost_snapshot,'line_total'=>(float)$l->line_total])->values(),'refunds'=>$row->refunds->map(fn($r)=>['id'=>$r->id,'refund_date'=>$r->refund_date?->toDateString(),'amount'=>(float)$r->amount,'reference'=>$r->reference,'receiving_account'=>$r->receivingAccount?->name,'received_by'=>$r->creator?->display_name])->values()];
    }

    private function options(User $actor): array
    {
        $blockedReceiptIds = InventoryStockReturn::query()
            ->where('business_id', $actor->business_id)
            ->where('status', 'PENDING')
            ->pluck('inventory_receipt_id')
            ->merge(
                InventoryReceiptActionRequest::query()
                    ->where('business_id', $actor->business_id)
                    ->where('status', 'PENDING')
                    ->pluck('inventory_receipt_id')
            )
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $receipts=InventoryReceipt::query()
            ->where('business_id',$actor->business_id)
            ->where('status','POSTED')
            ->when($blockedReceiptIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $blockedReceiptIds->all()))
            ->with(['lines.product','supplier','branch'])
            ->orderByDesc('received_date')
            ->limit(300)
            ->get()
            ->map(function($receipt){
            $lines=$receipt->lines->map(function($line){$state=$this->receiptActions->effectiveLineState($line);$returned=round((float)InventoryStockReturnLine::query()->join('inventory_stock_returns as r','r.id','=','inventory_stock_return_lines.inventory_stock_return_id')->where('inventory_stock_return_lines.inventory_receipt_line_id',$line->id)->where('r.status','APPROVED')->sum('inventory_stock_return_lines.quantity_returned'),4);return ['id'=>$line->id,'retail_product_id'=>$line->retail_product_id,'name'=>$line->product?->name,'sku'=>$line->product?->sku,'unit'=>$line->product?->unit,'received_quantity'=>$state['quantity_received'],'returned_quantity'=>$returned,'returnable_quantity'=>round(max(0,$state['quantity_received']-$returned),4),'unit_cost'=>$state['unit_cost']];})->filter(fn($l)=>$l['returnable_quantity']>0.00005)->values();
            return ['id'=>$receipt->id,'code'=>$receipt->code,'received_date'=>$receipt->received_date?->toDateString(),'branch_id'=>$receipt->branch_id,'branch'=>$receipt->branch?->name,'supplier_id'=>$receipt->supplier_id,'supplier'=>$receipt->supplier?->code_name?:$receipt->supplier?->company_name?:$receipt->supplier_name,'lines'=>$lines];
        })->filter(fn($r)=>count($r['lines'])>0)->values();
        $receivingAccounts=ChartOfAccount::query()->where('business_id',$actor->business_id)->where('active',true)->where('posting_type','POSTING')->where('is_cash_account',true)->where(function($q){$q->whereNull('system_key')->orWhereNotIn('system_key',['CASH_ON_HAND','PETTY_CASH','CASH_WITH_MANAGER','POS_CLEARING']);})->orderBy('code')->get(['id','code','name'])->map(fn($a)=>['id'=>$a->id,'code'=>$a->code,'name'=>$a->name])->values();
        $branches=DB::table('branches')->where('business_id',$actor->business_id)->where('status','ACTIVE')->orderBy('name')->get(['id','name']);
        return ['branches'=>$branches,'receipts'=>$receipts,'receiving_accounts'=>$receivingAccounts];
    }

    private function receiptOutstanding(InventoryReceipt $receipt): float
    {
        $grand=round((float)$receipt->total_cost+(float)$receipt->delivery_fee,2);
        $paid=round((float)collect($receipt->payment_splits?:[])->sum(fn($r)=>(float)($r['amount']??0)),2);
        $priorCredits=round((float)InventoryStockReturn::query()->where('inventory_receipt_id',$receipt->id)->where('status','APPROVED')->sum('ap_credit_amount'),2);
        return round(max(0,$grand-$paid-$priorCredits),2);
    }

    private function applyReturnStock(User $actor, InventoryStockReturn $return, InventoryStockReturnLine $line): void
    {
        $product=RetailProduct::query()->where('business_id',$actor->business_id)->whereKey($line->retail_product_id)->lockForUpdate()->firstOrFail();
        $stock=InventoryStock::query()->where('branch_id',$return->branch_id)->where('retail_product_id',$product->id)->lockForUpdate()->firstOrFail();
        $before=round((float)$stock->on_hand,4); $qty=round((float)$line->quantity_returned,4); $after=round($before-$qty,4); if($after<-0.00005) throw new DomainException($product->name.' does not have enough On Hand stock.');
        $stock->update(['on_hand'=>max(0,$after)]);
        $globalQtyBefore=round((float)InventoryStock::query()->where('business_id',$actor->business_id)->where('retail_product_id',$product->id)->sum('on_hand')+$qty,4);
        $globalQtyAfter=round($globalQtyBefore-$qty,4); $currentValue=round($globalQtyBefore*(float)$product->unit_cost,4); $newValue=round($currentValue-(float)$line->line_total,4); if($globalQtyAfter>0.00005 && $newValue>=-0.0001) $product->update(['unit_cost'=>round(max(0,$newValue)/$globalQtyAfter,4),'updated_by'=>$actor->id]);
        InventoryMovement::query()->create(['business_id'=>$actor->business_id,'branch_id'=>$return->branch_id,'retail_product_id'=>$product->id,'movement_type'=>'STOCK_RETURN','quantity_change'=>-$qty,'quantity_before'=>$before,'quantity_after'=>max(0,$after),'unit_cost_snapshot'=>(float)$line->unit_cost_snapshot,'reference_code'=>$return->code,'reason'=>'Stock returned to supplier. '.$return->reason,'created_by'=>$actor->id,'movement_at'=>$this->clock->now()]);
    }
    private function productMapping(int $businessId,int $productId): AccountingProductMapping { $m=AccountingProductMapping::query()->where('business_id',$businessId)->where('retail_product_id',$productId)->first(); if(!$m||!$m->inventory_account_id) throw new DomainException('Accounting Mapping is incomplete for one or more returned items.'); return $m; }
    private function assertOwned(User $actor, InventoryStockReturn $row): void { if((int)$row->business_id!==(int)$actor->business_id) abort(404); }
}
