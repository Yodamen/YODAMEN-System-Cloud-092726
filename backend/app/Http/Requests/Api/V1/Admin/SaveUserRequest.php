<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveUserRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'username' => strtolower(trim((string) $this->input('username'))),
            'display_name' => trim((string) $this->input('display_name')),
            'email' => strtolower(trim((string) $this->input('email'))),
            'status' => strtoupper(trim((string) $this->input('status', 'ACTIVE'))),
            'access_code' => trim((string) $this->input('access_code')),
        ]);
    }

    public function rules(): array
    {
        $userId = $this->route('user')?->id ?? $this->route('user');
        $minimum = (int) config('yodamen.authentication.minimum_access_code_length', 6);

        return [
            'username' => [
                'required', 'string', 'max:100',
                Rule::unique('users', 'username')->ignore($userId),
            ],
            'display_name' => ['required', 'string', 'max:255'],
            'email' => [
                'nullable', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'default_branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'personnel_id' => ['nullable', 'integer', 'exists:personnel,id'],
            'status' => ['required', 'in:ACTIVE,INACTIVE'],
            'access_code' => [
                $userId ? 'nullable' : 'required',
                'string',
                'min:'.$minimum,
                'max:255',
            ],
            'force_reset' => ['sometimes', 'boolean'],
        ];
    }
}
