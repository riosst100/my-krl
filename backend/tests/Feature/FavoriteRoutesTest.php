<?php

namespace Tests\Feature;

use App\Models\Schedule;
use App\Models\Station;
use App\Models\TrainLine;
use App\Models\TrainStop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FavoriteRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['THB' => 'Tanah Abang', 'SUD' => 'Sudirman', 'BKS' => 'Bekasi'] as $code => $name) {
            Station::create(['code' => $code, 'name' => $name, 'slug' => strtolower($code)]);
        }
        Station::create(['code' => 'SG', 'name' => 'Serang', 'slug' => 'serang', 'is_active' => false]);
    }

    public function test_guests_cannot_read_or_save_favorites(): void
    {
        $this->fromFrontend()->getJson('/api/v1/me/favorite-routes')->assertUnauthorized();
        $this->fromFrontend()->putJson('/api/v1/me/favorite-routes', ['routes' => [['from' => 'THB', 'to' => 'SUD']]])->assertUnauthorized();
        $this->fromFrontend()->getJson('/api/v1/me/favorite-routes/departures')->assertUnauthorized();
    }

    public function test_user_saves_one_to_four_routes_in_order(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')->fromFrontend()
            ->putJson('/api/v1/me/favorite-routes', ['routes' => [['from' => 'sud', 'to' => 'THB']]])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.from.code', 'SUD')
            ->assertJsonPath('data.0.to.code', 'THB')
            ->assertJsonPath('meta.min', 1)
            ->assertJsonPath('meta.max', 4);

        $four = [['from' => 'BKS', 'to' => 'SUD'], ['from' => 'SUD', 'to' => 'BKS'], ['from' => 'THB', 'to' => 'BKS'], ['from' => 'BKS', 'to' => 'THB']];
        $this->putJson('/api/v1/me/favorite-routes', ['routes' => $four])->assertOk()->assertJsonCount(4, 'data');

        $this->getJson('/api/v1/me/favorite-routes')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.from.code', 'BKS')
            ->assertJsonPath('data.0.to.code', 'SUD')
            ->assertJsonPath('data.3.position', 4);
    }

    public function test_route_rules(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web')->fromFrontend();
        $ok = ['from' => 'THB', 'to' => 'SUD'];

        $this->putJson('/api/v1/me/favorite-routes', ['routes' => []])->assertUnprocessable()->assertJsonValidationErrors('routes');
        $this->putJson('/api/v1/me/favorite-routes', ['routes' => array_fill(0, 5, $ok)])->assertUnprocessable()->assertJsonValidationErrors('routes');
        $this->putJson('/api/v1/me/favorite-routes', ['routes' => [['from' => 'THB', 'to' => 'thb']]])->assertUnprocessable()->assertJsonValidationErrors('routes.0.to');
        $this->putJson('/api/v1/me/favorite-routes', ['routes' => [['from' => 'THB', 'to' => 'SG']]])->assertUnprocessable()->assertJsonValidationErrors('routes.0.to');
        $this->putJson('/api/v1/me/favorite-routes', ['routes' => [['from' => 'THB']]])->assertUnprocessable()->assertJsonValidationErrors('routes.0.to');
        $this->putJson('/api/v1/me/favorite-routes', ['routes' => [$ok, $ok]])->assertUnprocessable()->assertJsonValidationErrors('routes.1.to');

        $this->assertSame(0, $user->favoriteRoutes()->count());
    }

    public function test_route_departures_only_list_trains_that_stop_at_the_destination(): void
    {
        $this->travelTo(now()->setTime(10, 0));
        $today = now()->toDateString();
        [$thb, $sud] = [Station::where('code', 'THB')->first(), Station::where('code', 'SUD')->first()];
        $bks = Station::where('code', 'BKS')->first();

        foreach (['5001' => '09:55', '5003' => '10:05', '5005' => '10:30', '5007' => '11:00'] as $number => $time) {
            Schedule::create(['station_id' => $thb->id, 'train_number' => $number, 'destination' => 'Bekasi', 'departure_time' => "{$time}:00", 'service_date' => $today]);
            // 5005 skips Sudirman (goes to Bekasi only).
            TrainStop::create(['service_date' => $today, 'train_number' => $number, 'sequence' => 1, 'station_code' => 'THB', 'station_id' => $thb->id, 'time' => "{$time}:00"]);
            TrainStop::create(['service_date' => $today, 'train_number' => $number, 'sequence' => 2, 'station_code' => (string) $number === '5005' ? 'BKS' : 'SUD', 'station_id' => (string) $number === '5005' ? $bks->id : $sud->id, 'time' => '10:50:00']);
        }

        $user = User::factory()->create();
        $this->actingAs($user, 'web')->fromFrontend()
            ->putJson('/api/v1/me/favorite-routes', ['routes' => [['from' => 'THB', 'to' => 'SUD'], ['from' => 'SUD', 'to' => 'THB']]])->assertOk();

        $this->getJson('/api/v1/me/favorite-routes/departures?limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.from.code', 'THB')
            ->assertJsonPath('data.0.to.code', 'SUD')
            ->assertJsonPath('data.0.has_schedules_today', true)
            ->assertJsonCount(2, 'data.0.departures')
            ->assertJsonPath('data.0.departures.0.train_number', '5003')
            ->assertJsonPath('data.0.departures.0.to_station_arrival_time', '10:50')
            ->assertJsonPath('data.0.departures.1.train_number', '5007') // 5005 does not stop at Sudirman
            ->assertJsonCount(0, 'data.1.departures');

        $this->getJson('/api/v1/me/favorite-routes/departures?limit=50')->assertUnprocessable();
    }

    public function test_next_departures_start_from_now(): void
    {
        $this->travelTo(now()->setTime(10, 0));
        $thb = Station::where('code', 'THB')->first();
        $line = TrainLine::create(['name' => 'COMMUTER LINE CIKARANG', 'color' => '#0084D8']);

        foreach (['09:55' => '5001', '10:05' => '5003', '10:30' => '5005', '11:00' => '5007'] as $time => $number) {
            Schedule::create([
                'station_id' => $thb->id, 'train_line_id' => $line->id, 'color' => '#0084D8', 'train_number' => $number,
                'destination' => 'Bekasi', 'departure_time' => "{$time}:00", 'service_date' => now()->toDateString(),
            ]);
        }

        $this->getJson('/api/v1/schedules/next?stations=THB,SUD&limit=2')
            ->assertOk()
            ->assertJsonPath('data.0.station.code', 'THB')
            ->assertJsonPath('data.0.has_schedules_today', true)
            ->assertJsonCount(2, 'data.0.departures')
            ->assertJsonPath('data.0.departures.0.train_number', '5003')
            ->assertJsonPath('data.0.departures.0.departure_time', '10:05')
            ->assertJsonPath('data.0.departures.1.train_number', '5005')
            ->assertJsonPath('data.1.station.code', 'SUD')
            ->assertJsonPath('data.1.has_schedules_today', false)
            ->assertJsonCount(0, 'data.1.departures');
    }

    public function test_next_departures_roll_over_to_the_next_service_date(): void
    {
        $this->travelTo(now()->setTime(23, 30));
        $thb = Station::where('code', 'THB')->first();

        Schedule::create(['station_id' => $thb->id, 'train_number' => '1', 'destination' => 'Bekasi', 'departure_time' => '22:54:00', 'service_date' => now()->toDateString()]);
        Schedule::create(['station_id' => $thb->id, 'train_number' => '2', 'destination' => 'Bekasi', 'departure_time' => '04:27:00', 'service_date' => now()->addDay()->toDateString()]);

        $this->getJson('/api/v1/schedules/next?stations=THB&limit=2')
            ->assertOk()
            ->assertJsonCount(1, 'data.0.departures')
            ->assertJsonPath('data.0.departures.0.train_number', '2')
            ->assertJsonPath('data.0.departures.0.service_date', now()->addDay()->toDateString());
    }

    public function test_upcoming_departures_across_stations_for_guests(): void
    {
        $this->travelTo(now()->setTime(10, 0));
        $thb = Station::where('code', 'THB')->first();
        $sud = Station::where('code', 'SUD')->first();
        $serang = Station::where('code', 'SG')->first(); // inactive: never listed
        $today = now()->toDateString();

        foreach ([[$thb, '09:50', '1'], [$sud, '10:02', '2'], [$thb, '10:04', '3'], [$serang, '10:01', '4'], [$sud, '10:20', '5']] as [$station, $time, $number]) {
            Schedule::create(['station_id' => $station->id, 'train_number' => $number, 'destination' => 'Bekasi', 'departure_time' => "{$time}:00", 'service_date' => $today]);
        }

        $this->getJson('/api/v1/schedules/upcoming?limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.train_number', '2')
            ->assertJsonPath('data.0.station.code', 'SUD')
            ->assertJsonPath('data.1.train_number', '3')
            ->assertJsonPath('data.1.station.code', 'THB')
            ->assertJsonPath('meta.has_schedules_today', true);

        // After the last train: empty list, but today's timetable exists ("no more trains today").
        $this->travelTo(now()->setTime(23, 30));
        $this->getJson('/api/v1/schedules/upcoming')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.has_schedules_today', true);
        $this->travelTo(now()->setTime(10, 0));

        // Only from one departure station.
        $this->getJson('/api/v1/schedules/upcoming?limit=5&station=thb')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.train_number', '3')
            ->assertJsonPath('meta.station.code', 'THB');

        $this->getJson('/api/v1/schedules/upcoming?limit=100')->assertUnprocessable();
        $this->getJson('/api/v1/schedules/upcoming?station=XYZ')->assertStatus(422);
        $this->getJson('/api/v1/schedules/upcoming?station=SG')->assertStatus(422); // inactive
    }

    public function test_next_departures_validation(): void
    {
        $this->getJson('/api/v1/schedules/next')->assertUnprocessable();
        $this->getJson('/api/v1/schedules/next?stations=THB;DROP')->assertUnprocessable();
        $this->getJson('/api/v1/schedules/next?stations=THB&limit=50')->assertUnprocessable();
    }
}
