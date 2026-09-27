<?php

namespace App\Http\Requests\Api\V1\Sales;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransactionActionRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'action_type' => ['required', Rule::in(['CORRECTION', 'REFUND', 'REVERSAL'])],
            'reason_code' => ['required', 'string', 'max:80'],
            'remarks' => ['required', 'string', 'max:2000'],
            'refund_method' => ['nullable', 'string', 'max:80'],
            'refund_reference' => ['nullable', 'string', 'max:180'],
            'attachment_tokens' => ['sometimes','array','max:10'],
            'attachment_tokens.*' => ['required','uuid','distinct'],
            'lines' => ['array'],
            'lines.*.sale_item_id' => ['nullable', 'integer'],
            'lines.*.line_action' => ['nullable', Rule::in(['ADD', 'EDIT', 'REMOVE', 'REFUND'])],
            'lines.*.item_kind' => ['nullable', Rule::in(['SERVICE', 'PRODUCT', 'SERVICE_CREDIT'])],
            'lines.*.quantity' => ['nullable', 'integer', 'min:1'],
            'lines.*.assigned_personnel_id' => ['nullable', 'integer'],
            'lines.*.service_id' => ['nullable', 'integer'],
            'lines.*.service_credit_id' => ['nullable', 'integer'],
            'lines.*.package_enrollment_id' => ['nullable', 'integer'],
            'lines.*.retail_product_id' => ['nullable', 'integer'],
            'lines.*.item_name' => ['nullable', 'string', 'max:180'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.refund_quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.refund_amount' => ['nullable', 'numeric', 'gt:0'],
            'lines.*.return_to_stock' => ['nullable', 'boolean'],
        ];
    }
}
