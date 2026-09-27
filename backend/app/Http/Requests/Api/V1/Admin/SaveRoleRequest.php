<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveRoleRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => strtoupper(trim((string) $this->input('code'))),
            'name' => trim((string) $this->input('name')),
            'description' => trim((string) $this->input('description')),
        ]);
    }

    public function rules(): array
    {
        $roleId = $this->route('role')?->id ?? $this->route('role');

        return [
            'code' => [
                $roleId ? 'nullable' : 'required',
                'string', 'max:80', 'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('roles', 'code')->ignore($roleId),
            ],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
