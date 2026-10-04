<?php

namespace App\Http\Requests;

use App\Models\Schedule;
use Illuminate\Foundation\Http\FormRequest;

class ScheduleFilterRequest extends FormRequest
{
    public function rules(): array
    {
        return [
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

    /**
     * Public schedules always show the latest synced timetable; a ?date= is ignored.
     */
    public function serviceDate(): string
    {
        return Schedule::query()->max('service_date') ?? now()->toDateString();
    }

    /**
     * @return array<string, string>
     */
    public function filters(): array
    {
        return [...$this->safe()->except(['per_page', 'to']), 'date' => $this->serviceDate()];
    }
}
