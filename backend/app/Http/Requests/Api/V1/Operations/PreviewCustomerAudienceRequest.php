<?php

namespace App\Http\Requests\Api\V1\Operations;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewCustomerAudienceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'match' => ['required', Rule::in(['ALL', 'ANY'])],
            'groups' => ['required', 'array', 'min:1', 'max:5'],
            'groups.*.match' => ['required', Rule::in(['ALL', 'ANY'])],
            'groups.*.conditions' => ['required', 'array', 'min:1', 'max:10'],
            'groups.*.conditions.*.field' => ['required', Rule::in([
                'SERVICE_AVAILED',
                'SERVICE_CATEGORY_AVAILED',
                'PACKAGE_PURCHASED',
                'VISIT_COUNT',
                'LIFETIME_VALUE',
                'LAST_VISIT',
                'PROMO_QUALIFIED',
                'LEAD_SOURCE',
                'BIRTH_MONTH',
                'CUSTOMER_STATUS',
                'CUSTOMER_TYPE',
                'CUSTOMER_CLASS',
            ])],
            'groups.*.conditions.*.operator' => ['required', 'string', 'max:60'],
            'groups.*.conditions.*.values' => ['sometimes', 'array', 'max:100'],
            'groups.*.conditions.*.values.*' => ['nullable'],
            'groups.*.conditions.*.value' => ['nullable'],
            'groups.*.conditions.*.value2' => ['nullable'],
            'groups.*.conditions.*.period_type' => ['nullable', Rule::in(['ALL_TIME', 'LAST_N_DAYS', 'BETWEEN_DATES'])],
            'groups.*.conditions.*.period_days' => ['nullable', 'integer', 'min:1', 'max:36500'],
            'groups.*.conditions.*.date_from' => ['nullable', 'date_format:Y-m-d'],
            'groups.*.conditions.*.date_to' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
