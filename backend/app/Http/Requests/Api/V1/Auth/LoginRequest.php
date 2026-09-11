<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'username' => strtolower(trim((string) $this->input('username'))),
            'access_code' => trim((string) $this->input('access_code')),
        ]);
    }

    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:100'],
            'access_code' => ['required', 'string'],
        ];
    }
}
