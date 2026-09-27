<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SaveIncentiveRuleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'rule_name'=>['required','string','max:160'],
            'branch_id'=>['nullable','integer','exists:branches,id'],
            'metric'=>['required','in:SALE_NET,SERVICE_NET,RETAIL_NET,ITEM_COUNT'],
            'target_scope'=>['required','in:ALL,PERSONNEL'],
            'personnel_id'=>['nullable','integer','exists:personnel,id'],
            'personnel_ids'=>['nullable','array'],
            'personnel_ids.*'=>['integer','distinct','exists:personnel,id'],
            'service_ids'=>['nullable','array'],
            'service_ids.*'=>['integer','distinct','exists:services,id'],
            'service_category_reference_ids'=>['nullable','array'],
            'service_category_reference_ids.*'=>['integer','distinct','exists:reference_values,id'],
            'threshold_value'=>['required','numeric','min:0','max:999999999.99'],
            'reward_type'=>['required','in:FIXED,PERCENTAGE'],
            'reward_value'=>['required','numeric','gt:0','max:999999999.9999'],
            'effective_from'=>['required','date_format:Y-m-d'],
            'effective_until'=>['nullable','date_format:Y-m-d','after_or_equal:effective_from'],
            'priority'=>['nullable','integer','min:-100000','max:100000'],
            'stackable'=>['sometimes','boolean'],
            'status'=>['required','in:ACTIVE,INACTIVE'],
            'notes'=>['nullable','string','max:5000'],
        ];
    }
}
