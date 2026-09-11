<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SaveServiceRequest extends FormRequest
{
    public function rules(): array
    {
        $creating = $this->route('serviceRecord') === null;

        return [
            'name' => ['required', 'string', 'max:255'],
            'category_code' => ['required', 'string', 'max:100'],
            'service_count_code' => ['required', 'string', 'max:100'],
            'number_of_sessions' => ['required', 'integer', 'min:1', 'max:999'],
            'duration_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status_code' => ['required', 'string', 'max:100'],
            'initial_price' => [$creating ? 'required' : 'nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'effective_from' => [$creating ? 'required' : 'nullable', 'date_format:Y-m-d'],
        ];
    }
}
