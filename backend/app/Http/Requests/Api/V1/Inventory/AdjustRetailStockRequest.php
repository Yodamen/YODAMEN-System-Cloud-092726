<?php

namespace App\Http\Requests\Api\V1\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class AdjustRetailStockRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'quantity_change' => ['required', 'numeric', 'not_in:0', 'min:-100000000', 'max:100000000'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
