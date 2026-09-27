<?php

namespace App\Http\Requests\Api\V1\Hr;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveLeaveRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'request_type' => ['required', Rule::in(['LEAVE', 'DAY_OFF'])],
            'leave_type_id' => ['nullable', 'integer'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'duration_type' => ['required', Rule::in(['FULL_DAY', 'HALF_DAY_AM', 'HALF_DAY_PM'])],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
