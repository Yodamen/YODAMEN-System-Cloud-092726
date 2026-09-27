<?php

namespace App\Http\Requests\Api\V1\Operations;

use Illuminate\Foundation\Http\FormRequest;

class CompleteVisitServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assigned_personnel_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'service_credit_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'retail_items' => ['sometimes', 'array', 'max:100'],
            'retail_items.*.id' => ['nullable', 'integer', 'min:1'],
            'retail_items.*.retail_product_id' => ['required', 'integer', 'exists:retail_products,id'],
            'retail_items.*.item_name' => ['nullable', 'string', 'max:255'],
            'retail_items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'retail_items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:999999999.99'],
            'retail_items.*.assigned_personnel_id' => ['required', 'integer', 'exists:personnel,id'],
            'retail_items.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
