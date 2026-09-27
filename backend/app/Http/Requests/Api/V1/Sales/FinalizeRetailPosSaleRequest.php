<?php

namespace App\Http\Requests\Api\V1\Sales;

use Illuminate\Foundation\Http\FormRequest;

class FinalizeRetailPosSaleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'discount_code' => ['nullable', 'string', 'max:80'],
            'discount_codes' => ['sometimes', 'array', 'max:8'],
            'discount_codes.*' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:190'],
            'attachment_tokens' => ['sometimes', 'array', 'max:10'],
            'attachment_tokens.*' => ['required', 'uuid', 'distinct'],

            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.retail_product_id' => ['required', 'integer', 'exists:retail_products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'items.*.assigned_personnel_id' => ['required', 'integer', 'exists:personnel,id'],

            'payments' => ['present', 'array', 'max:20'],
            'payments.*.payment_method_reference_id' => ['required', 'integer', 'exists:reference_values,id'],
            'payments.*.amount' => ['required', 'numeric', 'gt:0', 'max:999999999.99'],
            'payments.*.reference_no' => ['nullable', 'string', 'max:150'],
        ];
    }
}
