<?php

namespace App\Http\Requests\Api\V1\Hr;

use Illuminate\Foundation\Http\FormRequest;

class SaveLeaveBalanceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'entitled_days' => ['required', 'numeric', 'min:0', 'max:999'],
            'adjustment_days' => ['required', 'numeric', 'between:-999,999'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
