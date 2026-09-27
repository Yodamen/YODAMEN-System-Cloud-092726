<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ResetUserAccessCodeRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'access_code' => trim((string) $this->input('access_code')),
        ]);
    }

    public function rules(): array
    {
        $minimum = (int) config('yodamen.authentication.minimum_access_code_length', 6);

        return [
            'access_code' => ['required', 'string', 'min:'.$minimum, 'max:255'],
            'force_reset' => ['sometimes', 'boolean'],
        ];
    }
}
