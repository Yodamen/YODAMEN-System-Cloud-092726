<?php

namespace App\Services\Inventory;

use App\Models\Branch;
use App\Models\InventoryReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\RetailProduct;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Foundation\BusinessClock;
use App\Services\Foundation\CodeSequenceService;
use App\Services\Foundation\SupportingDocumentService;
use App\Services\Security\AuditService;
use DomainException;
use Illuminate\Support\Facades\DB;

class PurchaseOrderService
{
    public function __construct(
        private readonly BusinessClock $clock,
        private readonly CodeSequenceService $codes,
        private readonly AuditService $audit,
        private readonly InventoryControlService $inventory,
        private readonly SupportingDocumentService $documents,
        private readonly InventoryReceiptActionService $receiptActions,
    ) {}

    private function owned(User $actor, int $id, bool $lock = false): PurchaseOrder
    {
        $query = PurchaseOrder::query()->where('business_id', $actor->business_id)->whereKey($id);
        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    public function options(User $actor): array
    {
        return $this->inventory->receiptOptions($actor);
    }

    public function index(User $actor, array $filters): array
    {
        $query = PurchaseOrder::query()->where('business_id', $actor->business_id);
        if (!empty($filters['branch_id'])) $query->where('branch_id',(int)$filters['branch_id']);
        elseif ($actor->default_branch_id) $query->where('branch_id',$actor->default_branch_id);
        if (!empty($filters['status'])) $query->where('status',$filters['status']);
        if (!empty($filters['supplier_id'])) $query->where('supplier_id',(int)$filters['supplier_id']);
        $rows = $query->with(['supplier','branch','lines.product','receipts.lines.product','receipts.creator'])
            ->orderByDesc('id')->limit(300)->get();
        return ['orders'=>$rows->map(fn ($order) => $this->serialize($order))->values(), 'options'=>$this->options($actor)];
    }

    public function show(User $actor, int $id): array
    {
        return $this->serialize($this->owned($actor,$id)->load(['supplier','branch','lines.product','receipts.lines.product','receipts.creator']));
    }

    private function validatedLines(User $actor, Supplier $supplier, array $lines): array
    {
        if (!$lines) throw new DomainException('Add at least one Purchase Order line.');
        $seen=[]; $result=[];
        foreach ($lines as $line) {
            $id=(int)$line['retail_product_id'];
            if (isset($seen[$id])) throw new DomainException('Each product may appear only once on a Purchase Order.');
            $seen[$id]=true;
            $product=RetailProduct::query()->where('business_id',$actor->business_id)
                ->where('stock_type','INVENTORY')->where('status','ACTIVE')->find($id);
            if (!$product) throw new DomainException('Invalid or inactive inventory product.');
            if (!$supplier->items()->whereKey($id)->exists()) {
                throw new DomainException($product->name.' is not assigned to the selected supplier.');
            }
            $quantity=round((float)$line['purchase_quantity'],4);
            $factor=round((float)$line['conversion_factor'],6);
            $cost=round((float)$line['purchase_unit_cost'],2);
            if ($quantity<=0 || $factor<=0 || $cost<0) throw new DomainException('Purchase quantities and conversion factors must be positive. Costs cannot be negative.');
            $result[]=[
                'retail_product_id'=>$id, 'purchase_quantity'=>$quantity,
                'purchase_uom'=>trim($line['purchase_uom']),
                'conversion_factor'=>$factor,'purchase_unit_cost'=>$cost,
                'line_total'=>round($quantity*$cost,2),
            ];
        }
        return $result;
    }

    public function save(User $actor, array $data, ?int $id = null): array
    {
        return DB::transaction(function () use ($actor,$data,$id) {
            $branch=Branch::query()->where('business_id',$actor->business_id)->where('status','ACTIVE')->find((int)$data['branch_id']);
            $supplier=Supplier::query()->where('business_id',$actor->business_id)->where('status','ACTIVE')->find((int)$data['supplier_id']);
            if (!$branch || !$supplier) throw new DomainException('Select an active branch and supplier in this business.');
            $lines=$this->validatedLines($actor,$supplier,$data['lines']);
            if ($id) {
                $order=$this->owned($actor,$id,true);
                if ($order->status!=='DRAFT') throw new DomainException('Only draft Purchase Orders may be edited.');
            } else {
                $now=$this->clock->now();
                $code=$this->codes->next('PURCHASE_ORDER:'.$actor->business_id.':'.$now->format('my'),'PO-'.$now->format('my').'-',5,$actor->business_id);
                $order=new PurchaseOrder(['business_id'=>$actor->business_id,'code'=>$code,'status'=>'DRAFT','created_by'=>$actor->id]);
            }
            $order->fill([
                'branch_id'=>$branch->id,'supplier_id'=>$supplier->id,'order_date'=>$data['order_date'],
                'expected_delivery_date'=>$data['expected_delivery_date']??null,
                'reference_no'=>$data['reference_no']??null,'notes'=>$data['notes']??null,
                'total_cost'=>round(array_sum(array_column($lines,'line_total')),2),
            ])->save();
            $order->lines()->delete();
            foreach ($lines as $line) $order->lines()->create($line);
            $this->audit->record(action:$id?'UPDATE_PURCHASE_ORDER':'CREATE_PURCHASE_ORDER',module:'purchasing',entityType:PurchaseOrder::class,entityId:$order->id,after:['code'=>$order->code,'status'=>$order->status,'total_cost'=>$order->total_cost],actor:$actor);
            return $this->show($actor,(int)$order->id);
        });
    }

    public function transition(User $actor,int $id,string $action,?string $reason=null): array
    {
        return DB::transaction(function () use ($actor,$id,$action,$reason) {
            $order=$this->owned($actor,$id,true);
            $before=$order->status;
            if ($action==='SUBMIT' && $before==='DRAFT') $order->fill(['status'=>'SUBMITTED','submitted_by'=>$actor->id,'submitted_at'=>$this->clock->now()]);
            elseif ($action==='APPROVE' && $before==='SUBMITTED') $order->fill(['status'=>'APPROVED','approved_by'=>$actor->id,'approved_at'=>$this->clock->now()]);
            elseif ($action==='CANCEL' && in_array($before,['DRAFT','SUBMITTED','APPROVED'],true)) {
                if (trim((string)$reason)==='') throw new DomainException('Provide a cancellation reason.');
                if ($order->receipts()->where('status','POSTED')->exists()) throw new DomainException('Purchase Orders with posted receipts cannot be cancelled.');
                $order->fill(['status'=>'CANCELLED','cancelled_by'=>$actor->id,'cancelled_at'=>$this->clock->now(),'cancellation_reason'=>$reason]);
            } else throw new DomainException('This Purchase Order cannot perform the requested action in its current status.');
            $order->save();
            $this->audit->record(action:$action.'_PURCHASE_ORDER',module:'purchasing',entityType:PurchaseOrder::class,entityId:$id,before:['status'=>$before],after:['status'=>$order->status],reason:$reason,actor:$actor);
            return $this->show($actor,$id);
        });
    }

    private function receivedQuantity(PurchaseOrderLine $line): float
    {
        // Count effective quantities after approved receipt corrections, excluding reversed receipts.
        $sum=0.0;
        foreach ($line->receiptLines()->whereHas('receipt',fn ($q)=>$q->where('status','POSTED'))->get() as $receiptLine) {
            $state=$this->receiptActions->effectiveLineState($receiptLine);
            $sum+=(float)$state['purchase_quantity'];
        }
        return round($sum,4);
    }

    public function serialize(PurchaseOrder $order): array
    {
        $order->loadMissing(['supplier','branch','lines.product','receipts.lines.product','receipts.creator']);
        $lines=$order->lines->map(function (PurchaseOrderLine $line) {
            $received=$this->receivedQuantity($line);
            return [
                'id'=>$line->id,'retail_product_id'=>$line->retail_product_id,
                'product_name'=>$line->product?->name,'sku'=>$line->product?->sku,
                'purchase_quantity'=>(float)$line->purchase_quantity,
                'purchase_uom'=>$line->purchase_uom,
                'conversion_factor'=>(float)$line->conversion_factor,
                'purchase_unit_cost'=>(float)$line->purchase_unit_cost,
                'line_total'=>(float)$line->line_total,'received_quantity'=>$received,
                'remaining_quantity'=>max(0,round((float)$line->purchase_quantity-$received,4)),
            ];
        });
        $complete=$lines->isNotEmpty() && $lines->every(fn ($line)=>$line['remaining_quantity']<0.00005);
        $partial=$lines->contains(fn ($line)=>$line['received_quantity']>0);
        // Resolve historical all-reversed receipt sets without mutating records on GET.
        $allReceiptsReversed = $order->receipts->isNotEmpty()
            && $order->receipts->every(fn ($receipt) => $receipt->status === 'REVERSED');
        $effectiveStatus = $order->status === 'APPROVED' && $allReceiptsReversed
            ? 'REVERSED' : $order->status;
        return [
            'id'=>$order->id,'code'=>$order->code,'branch_id'=>$order->branch_id,
            'branch_name'=>$order->branch?->name,'supplier_id'=>$order->supplier_id,
            'supplier_name'=>$order->supplier?->company_name,'order_date'=>$order->order_date?->format('Y-m-d'),
            'expected_delivery_date'=>$order->expected_delivery_date?->format('Y-m-d'),
            'reference_no'=>$order->reference_no,'notes'=>$order->notes,'status'=>$effectiveStatus,
            'receiving_status'=>$complete?'FULLY_RECEIVED':($partial?'PARTIALLY_RECEIVED':'NOT_RECEIVED'),
            'total_cost'=>(float)$order->total_cost,'lines'=>$lines->values(),
            'receipts'=>$order->receipts->map(fn ($r)=>['id'=>$r->id,'code'=>$r->code,'status'=>$r->status,'received_date'=>$r->received_date?->format('Y-m-d'),'received_by'=>$r->creator?->display_name ?: $r->creator?->name,
                'lines'=>$r->lines->map(fn ($line) => [
                    'id'=>$line->id,
                    'product_name'=>$line->product?->name,
                    'purchase_quantity'=>(float) ($line->purchase_quantity ?? $line->quantity_received),
                    'purchase_uom'=>$line->purchase_uom ?: $line->product?->unit,
                ])->values(),
            ])->values(),
            'created_at'=>$order->created_at?->toIso8601String(),
        ];
    }

    public function receive(User $actor,int $id,array $data): array
    {
        return DB::transaction(function () use ($actor,$id,$data) {
            $order=$this->owned($actor,$id,true)->load('lines');
            if ($order->status!=='APPROVED') throw new DomainException('Only approved Purchase Orders may be received.');
            if ($order->receipts()->exists() && ! $order->receipts()->where('status','POSTED')->exists()) {
                throw new DomainException('This Purchase Order is reversed. Create a new Purchase Order for a replacement delivery.');
            }
            // Same-day delivery is valid; dates earlier than order creation are not.
            if ($data['received_date'] < $order->order_date->format('Y-m-d')) {
                throw new DomainException('Received date cannot be earlier than the Purchase Order date.');
            }
            $requested=collect($data['lines']);
            $poLines=$order->lines->keyBy('id');
            $input=[];
            foreach ($requested as $line) {
                $poLine=$poLines->get((int)$line['purchase_order_line_id']);
                if (!$poLine) throw new DomainException('Receiving line does not belong to this Purchase Order.');
                $quantity=round((float)$line['purchase_quantity'],4);
                if ($quantity<=0) throw new DomainException('Receiving quantity must be positive.');
                $remaining=round((float)$poLine->purchase_quantity-$this->receivedQuantity($poLine),4);
                if ($quantity-$remaining>0.00005) throw new DomainException('Receiving exceeds the approved remaining Purchase Order quantity.');
                $input[]=[
                    'retail_product_id'=>$poLine->retail_product_id,
                    'purchase_quantity'=>$quantity,'purchase_uom'=>$poLine->purchase_uom,
                    'conversion_factor'=>(float)$poLine->conversion_factor,
                    'purchase_unit_cost'=>(float)$poLine->purchase_unit_cost,
                    'po_line_id'=>$poLine->id,
                ];
            }
            if (!$input || count($input)!==count(array_unique(array_column($input,'retail_product_id')))) {
                throw new DomainException('Provide one distinct positive receiving quantity per product.');
            }
            // All actual inventory/AP postings are delegated to the existing tested service.
            $receipt=$this->inventory->receive($actor,[
                'branch_id'=>$order->branch_id,'supplier_id'=>$order->supplier_id,
                'received_date'=>$data['received_date'],
                'reference_no'=>$data['reference_no']??$order->reference_no,
                'notes'=>trim('Purchase Order '.$order->code.' '.($data['notes']??'')),
                'payment_status'=>'UNPAID','delivery_fee'=>0,'lines'=>$input,
            ]);
            $receipt->purchase_order_id=$order->id;
            $receipt->save();
            foreach ($input as $line) {
                $receipt->lines()->where('retail_product_id',$line['retail_product_id'])
                    ->update(['purchase_order_line_id'=>$line['po_line_id']]);
            }
            $this->documents->claimTokens($actor,$data['attachment_tokens'],'stock-received','INVENTORY_RECEIPT',(int)$receipt->id,'STOCK_RECEIVED_SUPPORT');
            $this->audit->record(action:'RECEIVE_PURCHASE_ORDER',module:'purchasing',entityType:PurchaseOrder::class,entityId:$order->id,after:['inventory_receipt_id'=>$receipt->id,'receipt_code'=>$receipt->code],actor:$actor);
            return ['order'=>$this->show($actor,$order->id),'receipt'=>['id'=>$receipt->id,'code'=>$receipt->code]];
        });
    }
}
