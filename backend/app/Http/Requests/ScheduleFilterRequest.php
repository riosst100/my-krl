<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ScheduleFilterRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'station' => ['sometimes', 'string', 'max:10'],
            // Destination station (code or slug): trains that stop there later.
            'to' => ['sometimes', 'string', 'max:120', 'alpha_dash'],
            'direction' => ['sometimes', 'string', 'max:100'],
            'line' => ['sometimes', 'string', 'max:100'],
            'train_number' => ['sometimes', 'string', 'alpha_num', 'max:20'],
            'time_from' => ['sometimes', 'date_format:H:i'],
            'time_to' => ['sometimes', 'date_format:H:i'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }

    public function serviceDate(): string
    {
        return $this->validated('date') ?? now()->toDateString();
    }

    /**
     * @return array<string, string>
     */
    public function filters(): array
    {
        return [...$this->safe()->except(['per_page', 'to']), 'date' => $this->serviceDate()];
    }
}
