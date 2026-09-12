<?php

namespace App\Http\Requests\Api\V1\Operations;

use Illuminate\Foundation\Http\FormRequest;

class AssignVisitServiceStaffRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return ['assigned_personnel_id' => ['nullable', 'integer', 'min:1']];
    }
}
