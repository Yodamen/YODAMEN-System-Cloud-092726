<?php

namespace App\Http\Requests\Api\V1\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class SaveRetailProductRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'sku' => ['nullable', 'string', 'max:100'],
            'barcode' => ['nullable', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:180'],
            'category' => ['nullable', 'string', 'max:120'],
            'brand' => ['nullable', 'string', 'max:120'],
            'unit' => ['required', 'string', 'max:40'],
            'purchase_uom' => ['nullable', 'string', 'max:40'],
            'purchase_to_basic_factor' => ['nullable', 'numeric', 'gt:0', 'max:999999999'],
            'purchase_uom_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999.9999'],
            'basic_uom_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999.9999'],
            'selling_uom' => ['nullable', 'string', 'max:40'],
            'selling_to_basic_factor' => ['nullable', 'numeric', 'gt:0', 'max:999999999'],
            'consumption_uom' => ['nullable', 'string', 'max:40'],
            'consumption_to_basic_factor' => ['nullable', 'numeric', 'gt:0', 'max:999999999'],
            'retail_available' => ['nullable', 'boolean'],
            'stock_type' => ['sometimes', 'in:INVENTORY,NON_INVENTORY,ASSET'],
            'unit_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'selling_price' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'selling_price_effective_from' => ['nullable', 'date_format:Y-m-d'],
            'reorder_point' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'commission_eligible' => ['nullable', 'boolean'],
            'status' => ['required', 'in:ACTIVE,INACTIVE'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
