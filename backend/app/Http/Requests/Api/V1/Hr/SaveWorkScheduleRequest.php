<?php

namespace App\Http\Requests\Api\V1\Hr;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveWorkScheduleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'weekly' => ['required', 'array', 'size:7'],
            'weekly.*.day_of_week' => ['required', 'integer', 'between:1,7', 'distinct'],
            'weekly.*.is_working_day' => ['required', 'boolean'],
            'weekly.*.expected_minutes' => ['required', 'integer', 'between:0,1440'],
            'weekly.*.start_time' => ['nullable', 'date_format:H:i'],
            'weekly.*.end_time' => ['nullable', 'date_format:H:i'],
            'overrides' => ['present', 'array'],
            'overrides.*.schedule_date' => ['required', 'date_format:Y-m-d', 'distinct'],
            'overrides.*.schedule_type' => ['required', Rule::in(['WORKDAY', 'DAY_OFF'])],
            'overrides.*.expected_minutes' => ['required', 'integer', 'between:0,1440'],
            'overrides.*.start_time' => ['nullable', 'date_format:H:i'],
            'overrides.*.end_time' => ['nullable', 'date_format:H:i'],
            'overrides.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
