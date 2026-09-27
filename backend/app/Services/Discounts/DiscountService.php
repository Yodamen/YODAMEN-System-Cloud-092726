<?php

namespace App\Services\Discounts;

use App\Models\DiscountRule;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\Operations\CustomerMetricsService;
use App\Services\Security\AuditService;
use Carbon\CarbonImmutable;
use DomainException;

class DiscountService
{
    private const EPSILON = 0.009;

    public function __construct(
        private readonly DiscountRuleService $rules,
        private readonly CustomerMetricsService $customerMetrics,
        private readonly AuditService $audit,
    ) {}

    /** @param string|array<int,string>|null $discountCodes */
    public function preview(User $actor, Sale $sale, string|array|null $discountCodes): array
    {
        $this->assertOwned($actor, $sale);
        if (! $sale->isPendingFinancialFinalization()) throw new DomainException('Promotions can only be applied before Checkout is finalized.');

        $sale->loadMissing(['booking','customer','items.service']);
        $codes = $this->normalizeCodes($discountCodes);
        $asOf = CarbonImmutable::parse($sale->sale_date ?: now());
        $candidateSets = [];
        foreach ($codes as $code) {
            $candidateSets[$code] = $this->rules->activeCandidatesForCode((int)$sale->business_id, $code, $asOf, $sale->branch_id);
            if ($candidateSets[$code]->isEmpty()) throw new DomainException('Promotion code '.$code.' is invalid, inactive, or outside its effective date.');
        }

        $metrics = ['last_visit'=>null,'days_since_last_visit'=>null,'lifetime_value'=>0.0,'total_completed_visits'=>0];
        if ($codes && $sale->customer_id) {
            $metrics = $this->customerMetrics->forCustomer((int)$sale->business_id,(int)$sale->customer_id,$sale->visit_id?(int)$sale->visit_id:null,(int)$sale->id);
        }

        $matched = array_fill_keys($codes, false);
        $nonStackable = [];
        $items = $sale->items
            ->reject(fn(SaleItem $item)=>in_array(strtoupper((string)($item->correction_status??'ACTIVE')),['REMOVED','REVERSED'],true))
            ->map(function(SaleItem $item) use($sale,$codes,$candidateSets,$metrics,&$matched,&$nonStackable){
                $gross=round((float)$item->line_total,2);
                $remaining=$gross;
                $applications=[];
                $isServiceCredit=strtoupper((string)$item->item_type)==='SERVICE_CREDIT_USE';
                if(!$isServiceCredit && $gross>self::EPSILON){
                    foreach($codes as $code){
                        $rule=$this->rules->resolveFromCandidates($sale,$item,$candidateSets[$code],$metrics);
                        if(!$rule) continue;
                        $matched[$code]=true;
                        if(!($rule->stackable??true)) $nonStackable[$code]=true;
                        $discount=$this->discountAmount($rule,$item,$remaining);
                        if($discount<=self::EPSILON) continue;
                        $remaining=max(0,round($remaining-$discount,2));
                        $applications[]=[
                            'id'=>$rule->id,'rule_id'=>$rule->id,'rule_code'=>$rule->code,'rule_name'=>$rule->rule_name,'promotion_code'=>$rule->discount_code,
                            'calculation_type'=>$rule->calculation_type,'rate_value'=>(float)$rule->rate_value,'discount_amount'=>$discount,'stackable'=>(bool)($rule->stackable??true),
                        ];
                    }
                }
                return ['sale_item_id'=>$item->id,'gross_amount'=>$gross,'discount_amount'=>round($gross-$remaining,2),'net_amount'=>$remaining,'applications'=>$applications,'rule'=>$applications[0]??null];
            })->values();

        foreach($codes as $code) if(!$matched[$code]) throw new DomainException('Promotion code '.$code.' is valid but does not match this customer or transaction.');
        if(count($codes)>1 && $nonStackable){
            $blocked=array_key_first($nonStackable);
            throw new DomainException('Promotion code '.$blocked.' cannot be stacked with another promotion.');
        }

        $gross=round((float)$items->sum('gross_amount'),2);
        $discount=round((float)$items->sum('discount_amount'),2);
        return [
            'discount_code'=>$codes[0]??null,
            'discount_codes'=>$codes,
            'promotion_codes'=>$codes,
            'gross_amount'=>$gross,'discount_amount'=>$discount,'net_amount'=>max(0,round($gross-$discount,2)),
            'customer_metrics'=>$metrics,'items'=>$items,
        ];
    }

    /** @param string|array<int,string>|null $discountCodes */
    public function apply(User $actor, Sale $sale, string|array|null $discountCodes): array
    {
        $preview=$this->preview($actor,$sale,$discountCodes);
        $sale->loadMissing('items');
        $rows=collect($preview['items'])->keyBy('sale_item_id');
        foreach($sale->items as $item){
            if(in_array(strtoupper((string)($item->correction_status??'ACTIVE')),['REMOVED','REVERSED'],true)) continue;
            $row=$rows->get($item->id); if(!$row) continue;
            $first=$row['applications'][0]??null;
            $item->update([
                'discount_rule_id'=>$first['rule_id']??null,'discount_code_snapshot'=>$first['promotion_code']??null,'discount_rule_name_snapshot'=>$first['rule_name']??null,
                'discount_calculation_type_snapshot'=>$first['calculation_type']??null,'discount_rate_value_snapshot'=>$first['rate_value']??null,
                'discount_amount'=>$row['discount_amount'],'promotion_applications'=>$row['applications'],'net_line_total'=>$row['net_amount'],'effective_net_line_total'=>$row['net_amount'],'updated_by'=>$actor->id,
            ]);
        }
        $sale->update([
            'discount_amount'=>$preview['discount_amount'],'net_amount'=>$preview['net_amount'],'effective_net_amount'=>$preview['net_amount'],
            'discount_code_snapshot'=>$preview['discount_code'],'promotion_codes_snapshot'=>$preview['promotion_codes'],'updated_by'=>$actor->id,
        ]);
        if($preview['discount_amount']>self::EPSILON){
            $this->audit->record('APPLY_CHECKOUT_PROMOTION','sales',Sale::class,$sale->id,null,[
                'promotion_codes'=>$preview['promotion_codes'],'gross_amount'=>$preview['gross_amount'],'discount_amount'=>$preview['discount_amount'],'net_amount'=>$preview['net_amount'],'items'=>$preview['items'],
            ],$actor);
        }
        return $preview;
    }

    private function normalizeCodes(string|array|null $codes): array
    {
        if(is_string($codes)) $codes=preg_split('/[\s,;+]+/',$codes,-1,PREG_SPLIT_NO_EMPTY)?:[];
        $normalized=[];
        foreach((array)$codes as $code){ $code=$this->rules->normalizeCode((string)$code); if($code!==''&&!in_array($code,$normalized,true))$normalized[]=$code; }
        if(count($normalized)>8) throw new DomainException('A maximum of 8 promotion codes may be stacked in one checkout.');
        return $normalized;
    }

    private function discountAmount(DiscountRule $rule, SaleItem $item, float $remaining): float
    {
        $rate=max(0,(float)$rule->rate_value);
        $discount=strtoupper((string)$rule->calculation_type)==='FIXED' ? $rate*max(1,(int)$item->quantity) : $remaining*($rate/100);
        return min($remaining,round($discount,2));
    }

    private function assertOwned(User $actor, Sale $sale): void
    {
        if((int)$sale->business_id!==(int)$actor->business_id) abort(404);
        if($actor->default_branch_id && (int)$sale->branch_id!==(int)$actor->default_branch_id) abort(404);
    }
}
