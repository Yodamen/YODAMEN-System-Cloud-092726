<?php

namespace App\Http\Requests\Api\V1\Hr;

use Illuminate\Foundation\Http\FormRequest;

class SavePersonnelRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'mobile_number' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'position_code' => ['required', 'string', 'max:100'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'employment_type_code' => ['nullable', 'string', 'max:100'],
            'personnel_type_code' => ['required', 'string', 'max:100'],
            'profit_sharing_eligible' => ['required', 'boolean'],
            'profit_sharing_percentage' => ['nullable', 'numeric', 'min:0', 'max:100', 'required_if:profit_sharing_eligible,true'],
            'profit_sharing_effective_from' => ['nullable', 'date_format:Y-m-d', 'required_if:profit_sharing_eligible,true'],
            'profit_sharing_effective_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:profit_sharing_effective_from'],
            'hourly_rate' => ['required', 'numeric', 'min:0', 'max:999999999.99'],
            'ot_entitled' => ['required', 'boolean'],
            'ot_multiplier' => ['nullable', 'numeric', 'gt:0', 'max:10'],
            'compensation_rule_code' => ['required', 'string', 'max:100'],
            'guaranteed_daily_pay' => ['required', 'numeric', 'min:0', 'max:999999999.99'],
            'compensation_effective_from' => ['nullable', 'date_format:Y-m-d'],
            'can_perform_services' => ['required', 'boolean'],
            'commission_eligible' => ['required', 'boolean'],
            'paid_leave_entitled' => ['sometimes', 'boolean'],
            'sss_enabled' => ['sometimes', 'boolean'],
            'sss_number' => ['nullable', 'string', 'max:40'],
            'philhealth_enabled' => ['sometimes', 'boolean'],
            'philhealth_number' => ['nullable', 'string', 'max:40'],
            'pagibig_enabled' => ['sometimes', 'boolean'],
            'pagibig_number' => ['nullable', 'string', 'max:40'],
            'hire_date' => ['nullable', 'date_format:Y-m-d'],
            'linked_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['required', 'in:ACTIVE,INACTIVE'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
