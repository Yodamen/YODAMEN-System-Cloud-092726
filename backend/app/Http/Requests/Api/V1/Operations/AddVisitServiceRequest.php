<?php

namespace App\Http\Requests\Api\V1\Operations;

use Illuminate\Foundation\Http\FormRequest;

class AddVisitServiceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'service_id' => ['required', 'integer', 'min:1'],
            'service_credit_id' => ['nullable', 'integer', 'min:1'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:100'],
            'assigned_personnel_id' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
