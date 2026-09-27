<?php

namespace App\Http\Requests\Api\V1\Sales;

use Illuminate\Foundation\Http\FormRequest;

class PreviewRetailPosSaleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'discount_code' => ['nullable', 'string', 'max:80'],
            'discount_codes' => ['sometimes', 'array', 'max:8'],
            'discount_codes.*' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.retail_product_id' => ['required', 'integer', 'exists:retail_products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'items.*.assigned_personnel_id' => ['nullable', 'integer', 'exists:personnel,id'],
        ];
    }
}
