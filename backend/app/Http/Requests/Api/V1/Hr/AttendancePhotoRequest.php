<?php

namespace App\Http\Requests\Api\V1\Hr;

use Illuminate\Foundation\Http\FormRequest;

class AttendancePhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'proof' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:2048'],
        ];
    }
}
