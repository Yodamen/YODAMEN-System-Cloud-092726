<?php

namespace App\Http\Requests\Api\V1\Hr;

use Illuminate\Foundation\Http\FormRequest;

class AdjustAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'time_in' => ['required', 'date_format:H:i'],
            'time_out' => ['required', 'date_format:H:i'],
            'ot_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
