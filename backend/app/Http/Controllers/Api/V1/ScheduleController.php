<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ScheduleFilterRequest;
use App\Http\Resources\ScheduleResource;
use App\Models\Station;
use App\Services\ScheduleSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ScheduleController extends Controller
{
    public function __construct(private readonly ScheduleSearchService $search) {}

    /**
     * GET /schedules — paginated search across all stations.
     */
    public function index(ScheduleFilterRequest $request): AnonymousResourceCollection
    {
        $page = $this->search->query($request->filters())
            ->paginate($request->integer('per_page', 50))
            ->withQueryString();

        return ScheduleResource::collection($page)->additional([
            'meta' => ['date' => $request->serviceDate(), 'last_synced_at' => $this->search->lastSyncedAt()],
        ]);
    }

    /**
     * GET /stations/{station}/schedules — full day for one station.
     */
    public function station(ScheduleFilterRequest $request, Station $station): AnonymousResourceCollection
    {
        $to = null;
        if ($request->filled('to')) {
            $to = (new Station)->resolveRouteBinding($request->string('to')->toString());
            abort_if($to === null, 422, 'The selected destination station is invalid.');
            abort_if($to->is($station), 422, 'The destination must be a different station.');
        }

        ['schedules' => $schedules, 'meta' => $meta] = $this->search->forStation($station, $request->filters(), $to);

        return ScheduleResource::collection($schedules)->additional([
            'meta' => [...$meta, 'count' => $schedules->count()],
        ]);
    }

    /**
     * GET /stations/{station}/destinations — stations reachable from here on a date.
     */
    public function destinations(ScheduleFilterRequest $request, Station $station): JsonResponse
    {
        return response()->json([
            'data' => $this->search->reachableStations($station, $request->serviceDate()),
            'meta' => ['station' => $station->code, 'date' => $request->serviceDate()],
        ]);
    }

    /**
     * GET /schedules/next?stations=THB,SUD&limit=2 — upcoming departures per station.
     */
    public function next(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'stations' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9]+(,[A-Za-z0-9]+){0,4}$/'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:5'],
        ]);

        $codes = array_values(array_unique(array_map('strtoupper', explode(',', $validated['stations']))));
        $stations = Station::active()->whereIn('code', $codes)->get()
            ->sortBy(fn (Station $s) => array_search($s->code, $codes, true))->values();

        $result = $this->search->nextDepartures($stations, (int) ($validated['limit'] ?? 2));

        return response()->json([
            'data' => $result->map(fn (array $row) => [
                'station' => ['code' => $row['station']->code, 'name' => $row['station']->name, 'slug' => $row['station']->slug],
                'has_schedules_today' => $row['has_schedules_today'],
                'departures' => ScheduleResource::collection($row['departures'])->resolve(),
            ])->values(),
            'meta' => [
                'now' => now()->toIso8601String(),
                'timezone' => config('app.timezone'),
                'last_synced_at' => $this->search->lastSyncedAt(),
            ],
        ]);
    }

    /**
     * GET /schedules/dates — service dates currently available in the database.
     */
    public function dates(): JsonResponse
    {
        return response()->json([
            'data' => $this->search->availableDates(),
            'meta' => ['today' => now()->toDateString(), 'timezone' => config('app.timezone')],
        ]);
    }
}
