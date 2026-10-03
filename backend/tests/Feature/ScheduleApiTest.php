<?php

namespace Tests\Feature;

use App\Models\Schedule;
use App\Models\Station;
use App\Models\TrainLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleApiTest extends TestCase
{
    use RefreshDatabase;

    private Station $bekasi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bekasi = Station::create(['code' => 'BKS', 'name' => 'Bekasi', 'slug' => 'bekasi']);
        $line = TrainLine::create(['name' => 'COMMUTER LINE CIKARANG', 'color' => '#0084D8']);

        $rows = [
            ['5002', 'Cikarang', '06:25:00', '2026-10-03'],
            ['5001', 'Kampung Bandan', '06:10:00', '2026-10-03'],
            ['5003', 'Kampung Bandan', '06:40:00', '2026-10-03'],
            ['5005', 'Kampung Bandan', '06:10:00', '2026-10-04'],
        ];

        foreach ($rows as [$number, $destination, $time, $date]) {
            Schedule::create([
                'station_id' => $this->bekasi->id,
                'train_line_id' => $line->id,
                'train_number' => $number,
                'destination' => $destination,
                'departure_time' => $time,
                'service_date' => $date,
            ]);
        }
    }

    public function test_station_schedules_for_a_date_are_sorted_by_departure(): void
    {
        $this->getJson('/api/v1/stations/BKS/schedules?date=2026-10-03')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.train_number', '5001')
            ->assertJsonPath('data.0.departure_time', '06:10')
            ->assertJsonPath('data.0.line.color', '#0084D8')
            ->assertJsonPath('meta.station.name', 'Bekasi')
            ->assertJsonPath('meta.destinations', ['Cikarang', 'Kampung Bandan']);
    }

    public function test_station_can_be_resolved_by_slug(): void
    {
        $this->getJson('/api/v1/stations/bekasi/schedules?date=2026-10-04')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_schedules_can_be_filtered_by_direction_and_time(): void
    {
        $this->getJson('/api/v1/stations/BKS/schedules?date=2026-10-03&direction=bandan&time_from=06:30')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.train_number', '5003');
    }

    public function test_unknown_station_returns_404(): void
    {
        $this->getJson('/api/v1/stations/XYZ/schedules')->assertNotFound()->assertJsonPath('message', 'Resource not found.');
    }

    public function test_invalid_filters_return_422(): void
    {
        $this->getJson('/api/v1/stations/BKS/schedules?date=03-10-2026&time_from=6pm')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date', 'time_from']);
    }

    public function test_global_search_by_train_number_is_paginated(): void
    {
        $this->getJson('/api/v1/schedules?date=2026-10-03&train_number=5003')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.station.code', 'BKS')
            ->assertJsonStructure(['data', 'links', 'meta' => ['current_page', 'total']]);
    }

    public function test_available_dates(): void
    {
        $this->getJson('/api/v1/schedules/dates')->assertOk()->assertJsonPath('data', ['2026-10-03', '2026-10-04']);
    }
}
