<?php

namespace App\Http\Requests\Api\V1\Hr;

use Illuminate\Foundation\Http\FormRequest;

class ReviewLeaveRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'review_reason_reference_id' => ['nullable','integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
