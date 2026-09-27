<?php

namespace App\Http\Requests\Api\V1\Sales;

use Illuminate\Foundation\Http\FormRequest;

class FinalizeCheckoutRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:190'],
            'discount_code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/'],
            'discount_codes' => ['sometimes', 'array', 'max:8'],
            'discount_codes.*' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/'],
            'advance_allocations' => ['present', 'array'],
            'advance_allocations.*.sale_item_id' => ['required', 'integer'],
            'advance_allocations.*.amount' => ['required', 'numeric', 'min:0'],
            'collected_allocations' => ['sometimes', 'array'],
            'collected_allocations.*.sale_item_id' => ['required', 'integer'],
            'collected_allocations.*.amount' => ['required', 'numeric', 'min:0'],
            'amount_collected_now' => ['required', 'numeric', 'min:0'],
            'payment_splits' => ['sometimes', 'array', 'max:8'],
            'payment_splits.*.payment_method_reference_id' => ['required', 'integer'],
            'payment_splits.*.amount' => ['required', 'numeric', 'gt:0'],
            'payment_splits.*.reference_no' => ['nullable', 'string', 'max:150'],
            'tip_amount' => ['sometimes', 'numeric', 'min:0'],
            'tip_personnel_id' => ['nullable', 'integer'],
            'tip_payment_method_reference_id' => ['nullable', 'integer'],
            // Legacy single-method checkout remains accepted for compatibility.
            'payment_method_reference_id' => ['nullable', 'integer'],
            'reference_no' => ['nullable', 'string', 'max:150'],
            'checkout_allocations' => ['present', 'array'],
            'checkout_allocations.*.sale_item_id' => ['required', 'integer'],
            'checkout_allocations.*.amount' => ['required', 'numeric', 'min:0'],
            'allow_remaining_balance' => ['sometimes', 'boolean'],
            'attachment_tokens' => ['sometimes','array','max:10'],
            'attachment_tokens.*' => ['required','uuid','distinct'],
        ];
    }
}
