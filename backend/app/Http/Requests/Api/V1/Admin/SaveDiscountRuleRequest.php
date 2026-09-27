<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SaveDiscountRuleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'rule_name' => ['required', 'string', 'max:160'],
            'discount_code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'personnel_ids' => ['nullable', 'array'],
            'personnel_ids.*' => ['integer', 'distinct', 'exists:personnel,id'],
            'applies_to' => ['required', 'in:ALL,CUSTOMER_TYPE,CUSTOMER'],
            'customer_id' => ['nullable', 'integer', 'required_if:applies_to,CUSTOMER', 'exists:customers,id'],
            'customer_type' => ['nullable', 'string', 'required_if:applies_to,CUSTOMER_TYPE', 'max:30'],
            'min_lifetime_value' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'max_lifetime_value' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'last_visit_operator' => ['nullable', 'in:MORE_THAN,WITHIN,BETWEEN,NEVER'],
            'last_visit_days_min' => ['nullable', 'integer', 'min:0', 'max:36500'],
            'last_visit_days_max' => ['nullable', 'integer', 'min:0', 'max:36500'],
            'item_scope' => ['required', 'in:ALL,SERVICE,PACKAGE,PRODUCT'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'service_ids' => ['nullable', 'array'],
            'service_ids.*' => ['integer', 'distinct', 'exists:services,id'],
            'service_category_reference_id' => ['nullable', 'integer', 'exists:reference_values,id'],
            'service_category_reference_ids' => ['nullable', 'array'],
            'service_category_reference_ids.*' => ['integer', 'distinct', 'exists:reference_values,id'],
            'calculation_type' => ['required', 'in:PERCENTAGE,FIXED'],
            'rate_value' => ['required', 'numeric', 'gt:0', 'max:999999999.9999'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'priority' => ['nullable', 'integer', 'min:-100000', 'max:100000'],
            'stackable' => ['sometimes', 'boolean'],
            'status' => ['required', 'in:ACTIVE,INACTIVE'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
