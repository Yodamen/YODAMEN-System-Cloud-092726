<?php

namespace App\Http\Requests\Api\V1\Sales;

use Illuminate\Foundation\Http\FormRequest;

class PreviewDiscountRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'discount_code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/'],
            'discount_codes' => ['sometimes', 'array', 'max:8'],
            'discount_codes.*' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/'],
        ];
    }
}
