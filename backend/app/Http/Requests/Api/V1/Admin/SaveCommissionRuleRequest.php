<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SaveCommissionRuleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'rule_name' => ['required', 'string', 'max:160'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'applies_to' => ['required', 'in:ALL,POSITION,EMPLOYEE'],
            'commission_scope' => ['required', 'in:SERVICE,RETAIL'],
            'personnel_id' => ['nullable', 'integer', 'exists:personnel,id'],
            'personnel_ids' => ['nullable', 'array'],
            'personnel_ids.*' => ['integer', 'distinct', 'exists:personnel,id'],
            'position_reference_id' => ['nullable', 'integer', 'required_if:applies_to,POSITION', 'exists:reference_values,id'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'service_ids' => ['nullable', 'array'],
            'service_ids.*' => ['integer', 'distinct', 'exists:services,id'],
            'service_category_reference_id' => ['nullable', 'integer', 'exists:reference_values,id'],
            'service_category_reference_ids' => ['nullable', 'array'],
            'service_category_reference_ids.*' => ['integer', 'distinct', 'exists:reference_values,id'],
            'retail_product_id' => ['nullable', 'integer', 'exists:retail_products,id', 'prohibits:retail_category'],
            'retail_category' => ['nullable', 'string', 'max:120', 'prohibits:retail_product_id'],
            'calculation_type' => ['required', 'in:PERCENTAGE,FIXED'],
            'rate_value' => ['required', 'numeric', 'gt:0', 'max:999999999.9999'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'priority' => ['nullable', 'integer', 'min:-100000', 'max:100000'],
            'status' => ['required', 'in:ACTIVE,INACTIVE'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
