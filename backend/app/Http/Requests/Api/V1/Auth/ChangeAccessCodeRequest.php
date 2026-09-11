<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ChangeAccessCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'access_code' => trim((string) $this->input('access_code')),
            'access_code_confirmation' => trim((string) $this->input('access_code_confirmation')),
        ]);
    }

    public function rules(): array
    {
        $minimum = (int) config('yodamen.authentication.minimum_access_code_length', 6);

        return [
            'access_code' => ['required', 'string', 'min:'.$minimum, 'confirmed'],
        ];
    }
}
