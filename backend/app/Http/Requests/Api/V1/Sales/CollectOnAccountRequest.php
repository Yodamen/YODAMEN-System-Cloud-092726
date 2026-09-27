<?php

namespace App\Http\Requests\Api\V1\Sales;

use Illuminate\Foundation\Http\FormRequest;

class CollectOnAccountRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:190'],
            'amount_collected' => ['required', 'numeric', 'gt:0'],
            'payment_method_reference_id' => ['required', 'integer'],
            'reference_no' => ['nullable', 'string', 'max:150'],
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.sale_item_id' => ['required', 'integer'],
            'allocations.*.amount' => ['required', 'numeric', 'min:0'],
        ];
    }
}
