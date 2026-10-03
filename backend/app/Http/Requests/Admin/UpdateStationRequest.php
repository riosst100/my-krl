<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'is_active' => ['sometimes', 'boolean'],
            // KCI does not publish coordinates; admins can fill them in (sync never overwrites them).
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
        ];
    }
}
