<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\SyncStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateStationRequest;
use App\Http\Resources\StationResource;
use App\Http\Resources\SyncLogResource;
use App\Models\Station;
use App\Models\SyncLog;
use App\Services\AdminStationService;
use App\Services\StationSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

class StationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', 'in:active,inactive'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $stations = Station::query()
            ->search($request->input('search'))
            ->when($request->input('status'), fn ($q, $status) => $q->where('is_active', $status === 'active'))
            ->withCount(['schedules' => fn ($q) => $q->whereDate('service_date', now()->toDateString())])
            ->orderBy('name')
            ->paginate($request->integer('per_page', 50))
            ->withQueryString();

        $lastSync = SyncLog::with('triggeredBy')->where('type', SyncLog::TYPE_KCI_STATIONS)->latest('id')->first();
        $lastSuccess = SyncLog::where('type', SyncLog::TYPE_KCI_STATIONS)
            ->where('status', SyncStatus::Success)
            ->latest('finished_at')
            ->first();

        return StationResource::collection($stations)->additional([
            'meta' => [
                'last_station_sync' => $lastSync ? new SyncLogResource($lastSync) : null,
                'last_successful_station_sync' => $lastSuccess ? new SyncLogResource($lastSuccess) : null,
                'station_sync_in_progress' => SyncLog::inProgress(SyncLog::TYPE_KCI_STATIONS)->exists(),
                'next_station_sync' => $this->nextMonthlyRun()->toIso8601String(),
            ],
        ]);
    }

    public function show(Station $station, AdminStationService $service): JsonResponse
    {
        return response()->json([
            'data' => [
                ...(new StationResource($station))->resolve(),
                ...$service->detail($station),
            ],
        ]);
    }

    public function update(UpdateStationRequest $request, Station $station): StationResource
    {
        $station->update($request->validated());

        return new StationResource($station);
    }

    /**
     * POST /admin/stations/sync — queue a station-list sync now.
     */
    public function sync(Request $request, StationSyncService $sync): JsonResponse
    {
        $log = $sync->queueManualSync($request->user());

        if (! $log) {
            return response()->json(['message' => 'A station synchronization is already in progress.'], Response::HTTP_CONFLICT);
        }

        return (new SyncLogResource($log->refresh()->load('triggeredBy')))
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }

    private function nextMonthlyRun(): Carbon
    {
        [$hour, $minute] = array_map('intval', explode(':', config('kci.station_sync_time')));
        $run = now()->startOfMonth()->day(config('kci.station_sync_day'))->setTime($hour, $minute);

        return $run->isPast() ? $run->addMonthNoOverflow() : $run;
    }
}
