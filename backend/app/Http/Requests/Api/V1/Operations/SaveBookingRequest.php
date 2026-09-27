<?php

namespace App\Http\Requests\Api\V1\Operations;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer'],
            'customer_type' => ['nullable', 'string', 'max:30'],
            'booking_date' => ['required', 'date'],
            'booking_time' => ['required', 'date_format:H:i'],
            'booking_source' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'services' => ['required_without:retail_items', 'array'],
            'services.*.service_id' => ['required', 'integer'],
            'services.*.service_credit_id' => ['nullable', 'integer'],
            'services.*.quantity' => ['nullable', 'integer', 'min:1', 'max:100'],
            'services.*.assigned_personnel_id' => ['nullable', 'integer'],
            'services.*.notes' => ['nullable', 'string', 'max:2000'],
            'retail_items' => ['required_without:services', 'array'],
            'retail_items.*.retail_product_id' => ['nullable', 'integer', 'exists:retail_products,id'],
            'retail_items.*.item_name' => ['nullable', 'string', 'max:255'],
            'retail_items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'retail_items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'retail_items.*.assigned_personnel_id' => ['nullable', 'integer', 'exists:personnel,id'],
            'retail_items.*.notes' => ['nullable', 'string', 'max:2000'],
            'payment_collection' => ['nullable', 'array'],
            'payment_collection.collected' => ['nullable', 'boolean'],
            'payment_collection.type' => ['nullable', Rule::in(['ADVANCE', 'INTERIM', 'MIXED'])],
            'payment_collection.amount' => ['nullable', Rule::requiredIf(fn () => $this->boolean('payment_collection.collected')), 'numeric', 'gt:0', 'max:9999999999.99'],
            'payment_collection.advance_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'payment_collection.payment_method_reference_id' => ['nullable', Rule::requiredIf(fn () => $this->boolean('payment_collection.collected')), 'integer'],
            'payment_collection.reference_no' => ['nullable', 'string', 'max:150'],
            'payment_collection.bank_account' => ['nullable', 'string', 'max:150'],
            'payment_collection.notes' => ['nullable', 'string', 'max:2000'],
            'payment_collection.allocations' => ['nullable', 'array'],
            'payment_collection.allocations.*.sale_item_id' => ['nullable', 'integer'],
            'payment_collection.allocations.*.opening_receivable_id' => ['nullable', 'integer'],
            'payment_collection.allocations.*.amount' => ['required_with:payment_collection.allocations', 'numeric', 'gt:0', 'max:9999999999.99'],
            'payment_collection.attachment_tokens' => ['sometimes', 'array', 'max:10'],
            'payment_collection.attachment_tokens.*' => ['required', 'uuid', 'distinct'],
            'advance_payment' => ['nullable', 'array'],
            'advance_payment.collected' => ['nullable', 'boolean'],
            'advance_payment.amount' => ['nullable', Rule::requiredIf(fn () => $this->boolean('advance_payment.collected')), 'numeric', 'gt:0', 'max:9999999999.99'],
            'advance_payment.payment_method_reference_id' => ['nullable', Rule::requiredIf(fn () => $this->boolean('advance_payment.collected')), 'integer'],
            'advance_payment.reference_no' => ['nullable', 'string', 'max:150'],
            'advance_payment.bank_account' => ['nullable', 'string', 'max:150'],
            'advance_payment.notes' => ['nullable', 'string', 'max:2000'],
            'advance_payment.attachment_tokens' => ['sometimes', 'array', 'max:10'],
            'advance_payment.attachment_tokens.*' => ['required', 'uuid', 'distinct'],
        ];
    }
}
