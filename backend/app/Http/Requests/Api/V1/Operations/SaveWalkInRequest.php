<?php

namespace App\Http\Requests\Api\V1\Operations;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveWalkInRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer'],
            'customer_type' => ['required', Rule::in(['NEW', 'RETURNEE'])],
            'notes' => ['nullable', 'string', 'max:5000'],
            'services' => ['required_without:retail_items', 'array'],
            'services.*.service_id' => ['required', 'integer'],
            'services.*.quantity' => ['nullable', 'integer', 'min:1', 'max:100'],
            'services.*.assigned_personnel_id' => ['nullable', 'integer'],
            'services.*.notes' => ['nullable', 'string', 'max:2000'],
            'retail_items' => ['required_without:services', 'array'],
            'retail_items.*.item_name' => ['required', 'string', 'max:255'],
            'retail_items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'retail_items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'retail_items.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
