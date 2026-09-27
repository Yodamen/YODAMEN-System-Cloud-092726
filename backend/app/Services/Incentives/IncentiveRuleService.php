<?php

namespace App\Services\Incentives;

use App\Models\Branch;
use App\Models\IncentiveRule;
use App\Models\Personnel;
use App\Models\ReferenceGroup;
use App\Models\ReferenceValue;
use App\Models\Service;
use App\Models\User;
use App\Services\Foundation\CodeSequenceService;
use App\Services\Security\AuditService;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class IncentiveRuleService
{
    public function __construct(private readonly CodeSequenceService $codes, private readonly AuditService $audit) {}

    public function list(int $businessId): Collection
    {
        return IncentiveRule::query()->where('business_id',$businessId)->with(['branch','personnel'])->orderByDesc('priority')->orderBy('rule_name')->get()->map(fn($r)=>$this->serialize($r));
    }

    public function formOptions(int $businessId): array
    {
        $services = Service::query()->where('business_id',$businessId)->with(['statusReference','category'])->orderBy('name')->get()
            ->filter(fn(Service $service)=>strtoupper((string)$service->statusReference?->code)==='ACTIVE')
            ->map(fn(Service $service)=>['id'=>$service->id,'code'=>$service->code,'name'=>$service->name,'category_id'=>$service->service_category_reference_id,'category_name'=>$service->category?->display_value])->values();

        return [
            'branches'=>Branch::query()->where('business_id',$businessId)->where('status','ACTIVE')->orderBy('name')->get(['id','code','name']),
            'personnel'=>Personnel::query()->where('business_id',$businessId)->where('status','ACTIVE')->orderBy('last_name')->orderBy('first_name')->get()->map(fn($p)=>['id'=>$p->id,'code'=>$p->code,'name'=>$p->full_name])->values(),
            'services'=>$services,
            'service_categories'=>$this->referenceValues($businessId,'SERVICE_CATEGORY'),
        ];
    }

    public function create(User $actor, array $data): IncentiveRule
    {
        return DB::transaction(function() use($actor,$data){
            $data=$this->normalize($actor,$data);
            $rule=IncentiveRule::create([...$data,'business_id'=>$actor->business_id,'code'=>$this->codes->next('INCENTIVE_RULE','INC-',5,$actor->business_id),'created_by'=>$actor->id,'updated_by'=>$actor->id]);
            $this->audit->record(action:'CREATE_INCENTIVE_RULE', module:'incentive-rules', entityType:IncentiveRule::class, entityId:$rule->id, after:$this->serialize($rule), actor:$actor);
            return $rule->fresh(['branch','personnel']);
        });
    }

    public function updateById(User $actor,int $id,array $data): IncentiveRule
    {
        return DB::transaction(function() use($actor,$id,$data){
            $rule=IncentiveRule::query()->where('business_id',$actor->business_id)->lockForUpdate()->findOrFail($id);
            $before=$this->serialize($rule);
            $rule->update([...$this->normalize($actor,$data),'updated_by'=>$actor->id]);
            $this->audit->record(action:'UPDATE_INCENTIVE_RULE', module:'incentive-rules', entityType:IncentiveRule::class, entityId:$rule->id, before:$before, after:$this->serialize($rule->fresh(['branch','personnel'])), actor:$actor);
            return $rule->fresh(['branch','personnel']);
        });
    }

    public function toggleById(User $actor,int $id): IncentiveRule
    {
        $rule=IncentiveRule::query()->where('business_id',$actor->business_id)->findOrFail($id);
        $rule->update(['status'=>$rule->status==='ACTIVE'?'INACTIVE':'ACTIVE','updated_by'=>$actor->id]);
        return $rule->fresh(['branch','personnel']);
    }

    private function normalize(User $actor,array $data): array
    {
        if (!empty($data['branch_id']) && !Branch::query()->where('business_id',$actor->business_id)->whereKey($data['branch_id'])->exists()) throw new DomainException('Selected branch is unavailable.');

        $targetScope=strtoupper((string)($data['target_scope']??'ALL'));
        $personnelIds=$targetScope==='PERSONNEL'?$this->inputIds($data,'personnel_ids','personnel_id'):[];
        if($targetScope==='PERSONNEL' && empty($personnelIds)) throw new DomainException('Select at least one personnel for this incentive rule.');
        foreach($personnelIds as $id){
            if(!Personnel::query()->where('business_id',$actor->business_id)->where('status','ACTIVE')->whereKey($id)->exists()) throw new DomainException('Selected personnel is unavailable.');
        }

        $serviceIds=$this->inputIds($data,'service_ids',null);
        $categoryIds=$this->inputIds($data,'service_category_reference_ids',null);
        foreach($serviceIds as $id){
            if(!Service::query()->where('business_id',$actor->business_id)->whereKey($id)->exists()) throw new DomainException('Selected service is unavailable.');
        }
        foreach($categoryIds as $id) $this->assertReferenceValue((int)$actor->business_id,'SERVICE_CATEGORY',$id);

        $metric=strtoupper((string)$data['metric']);
        if($metric==='RETAIL_NET' && (!empty($serviceIds)||!empty($categoryIds))) throw new DomainException('Service and Service Category filters do not apply to Retail Net incentives.');

        $reward=round((float)$data['reward_value'],4);
        if (($data['reward_type']??'FIXED')==='PERCENTAGE' && $reward>100) throw new DomainException('Percentage incentive cannot exceed 100%.');

        return [
            'rule_name'=>trim($data['rule_name']),'branch_id'=>$data['branch_id']??null,'metric'=>$metric,
            'target_scope'=>$targetScope,'personnel_id'=>$personnelIds[0]??null,'personnel_ids'=>$personnelIds?:null,
            'service_ids'=>$serviceIds?:null,'service_category_reference_ids'=>$categoryIds?:null,
            'threshold_value'=>round((float)$data['threshold_value'],2),'reward_type'=>strtoupper($data['reward_type']),'reward_value'=>$reward,
            'effective_from'=>$data['effective_from'],'effective_until'=>$data['effective_until']??null,'priority'=>(int)($data['priority']??0),
            'stackable'=>(bool)($data['stackable']??true),'status'=>strtoupper($data['status']),'notes'=>$data['notes']??null,
        ];
    }

    public function serialize(IncentiveRule $r): array
    {
        $r->loadMissing(['branch','personnel']);
        $today=now()->toDateString(); $display=strtoupper($r->status);
        if($display==='ACTIVE'){ if($r->effective_from?->toDateString()>$today)$display='SCHEDULED'; elseif($r->effective_until && $r->effective_until->toDateString()<$today)$display='EXPIRED'; }

        $personnelIds=$this->ruleIds($r->personnel_ids,$r->personnel_id);
        $serviceIds=$this->ruleIds($r->service_ids);
        $categoryIds=$this->ruleIds($r->service_category_reference_ids);
        $personnelList=Personnel::query()->where('business_id',$r->business_id)->whereIn('id',$personnelIds)->orderBy('last_name')->orderBy('first_name')->get()->map(fn($p)=>['id'=>$p->id,'code'=>$p->code,'name'=>$p->full_name])->values();
        $serviceList=Service::query()->where('business_id',$r->business_id)->whereIn('id',$serviceIds)->orderBy('name')->get()->map(fn($s)=>['id'=>$s->id,'code'=>$s->code,'name'=>$s->name])->values();
        $categoryList=ReferenceValue::query()->whereIn('id',$categoryIds)->orderBy('display_value')->get()->map(fn($v)=>['id'=>$v->id,'code'=>$v->code,'name'=>$v->display_value])->values();

        return [
            'id'=>$r->id,'code'=>$r->code,'rule_name'=>$r->rule_name,'branch'=>$r->branch?['id'=>$r->branch->id,'name'=>$r->branch->name]:null,
            'metric'=>$r->metric,'target_scope'=>$r->target_scope,'personnel'=>$r->personnel?['id'=>$r->personnel->id,'name'=>$r->personnel->full_name]:null,
            'personnel_ids'=>$personnelIds,'personnel_list'=>$personnelList,'service_ids'=>$serviceIds,'service_list'=>$serviceList,
            'service_category_reference_ids'=>$categoryIds,'service_category_list'=>$categoryList,
            'threshold_value'=>(float)$r->threshold_value,'reward_type'=>$r->reward_type,'reward_value'=>(float)$r->reward_value,
            'effective_from'=>$r->effective_from?->toDateString(),'effective_until'=>$r->effective_until?->toDateString(),'priority'=>(int)$r->priority,
            'stackable'=>(bool)$r->stackable,'status'=>$r->status,'display_status'=>$display,'notes'=>$r->notes,
        ];
    }

    private function inputIds(array $data,string $pluralKey,?string $singularKey): array
    {
        $values=$data[$pluralKey]??[];
        if(!is_array($values))$values=[];
        if(empty($values)&&$singularKey&&!empty($data[$singularKey]))$values=[$data[$singularKey]];
        return collect($values)->map(fn($v)=>(int)$v)->filter()->unique()->values()->all();
    }

    private function ruleIds(?array $values,mixed $legacyValue=null): array
    {
        $ids=collect($values?:[])->map(fn($v)=>(int)$v)->filter()->unique()->values()->all();
        if(empty($ids)&&$legacyValue)$ids=[(int)$legacyValue];
        return $ids;
    }

    private function referenceValues(int $businessId,string $groupCode): Collection
    {
        $group=ReferenceGroup::query()->where('business_id',$businessId)->where('code',$groupCode)->first();
        if(!$group)return collect();
        return ReferenceValue::query()->where('reference_group_id',$group->id)->where('active',true)->orderBy('sort_order')->orderBy('display_value')->get()->map(fn($v)=>['id'=>$v->id,'code'=>$v->code,'name'=>$v->display_value])->values();
    }

    private function assertReferenceValue(int $businessId,string $groupCode,int $valueId): void
    {
        $ok=ReferenceValue::query()->whereKey($valueId)->where('active',true)->whereHas('group',fn($q)=>$q->where('business_id',$businessId)->where('code',$groupCode))->exists();
        if(!$ok)throw new DomainException('Selected Service Category is unavailable.');
    }
}
