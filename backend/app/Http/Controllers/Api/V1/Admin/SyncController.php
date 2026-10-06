<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\SyncStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SyncLogResource;
use App\Models\KciTimetableCheck;
use App\Models\SyncLog;
use App\Models\SyncLogRequest;
use App\Services\AutoSyncService;
use App\Services\Kci\Clients\KciUrlClient;
use App\Services\KciTimetableWatchService;
use App\Models\Station;
use App\Services\Kci\Exceptions\KciApiException;
use App\Services\ManualImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

class SyncController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'type' => ['sometimes', 'nullable', 'in:schedules,stations'],
        ]);

        $type = match ($request->input('type')) {
            'schedules' => SyncLog::TYPE_KCI_SCHEDULES,
            'stations' => SyncLog::TYPE_KCI_STATIONS,
            default => null,
        };

        $logs = SyncLog::with('triggeredBy')
            ->when($type, fn ($q) => $q->where('type', $type))
            ->latest('id')
            ->paginate($request->integer('per_page', 20));

        // "The sync": Sync dari KCI, manual or automatic.
        $syncs = fn () => SyncLog::query()->where('type', SyncLog::TYPE_KCI_SCHEDULES)->whereIn('trigger', ['manual', 'schedule']);
        $last = $syncs()->with('triggeredBy')->latest('id')->first();
        $lastSuccess = $syncs()->whereIn('status', [SyncStatus::Success, SyncStatus::Partial])->latest('finished_at')->first();

        return SyncLogResource::collection($logs)->additional([
            'meta' => [
                'in_progress' => $syncs()->whereIn('status', [SyncStatus::Queued, SyncStatus::Running])
                    ->where('created_at', '>=', now()->subMinutes(config('kci.stale_after_minutes')))->exists(),
                'last_sync' => $last ? new SyncLogResource($last) : null,
                'last_successful_sync' => $lastSuccess ? new SyncLogResource($lastSuccess) : null,
            ],
        ]);
    }

    public function show(SyncLog $syncLog): SyncLogResource
    {
        return new SyncLogResource($syncLog->load('triggeredBy'));
    }

    /**
     * GET /admin/sync-logs/{id}/requests?after= — the KCI requests of one sync run
     * (URL + outcome, no body), oldest first. Poll with after = last seen id.
     */
    public function requests(Request $request, SyncLog $syncLog): JsonResponse
    {
        $request->validate(['after' => ['sometimes', 'integer', 'min:0']]);

        $rows = SyncLogRequest::where('sync_log_id', $syncLog->id)
            ->where('id', '>', $request->integer('after'))
            ->orderBy('id')
            ->limit(500)
            ->get(['id', 'url', 'status_code', 'ok', 'message', 'duration_ms', 'created_at']);

        $counts = SyncLogRequest::where('sync_log_id', $syncLog->id)
            ->selectRaw('count(*) as total, count(*) filter (where ok) as ok_count')
            ->first();

        return response()->json([
            'data' => $rows->map(fn (SyncLogRequest $row) => [
                ...$row->only(['id', 'url', 'status_code', 'ok', 'message', 'duration_ms']),
                'created_at' => $row->created_at->toIso8601String(),
            ]),
            'meta' => [
                'total' => (int) $counts->total,
                'ok' => (int) $counts->ok_count,
                'failed' => (int) $counts->total - (int) $counts->ok_count,
            ],
        ]);
    }

    /**
     * GET /admin/kci-watch — when did KCI's timetable change (publish times)?
     */
    public function watch(KciTimetableWatchService $watch): JsonResponse
    {
        $report = $watch->report();
        $check = fn (?KciTimetableCheck $c) => $c ? [
            'checked_at' => $c->checked_at->toIso8601String(),
            'ok' => $c->ok,
            'trains' => $c->trains,
            'first_departure' => $c->first_departure,
            'last_departure' => $c->last_departure,
            'changed' => $c->changed,
            'summary' => $c->diff['summary'] ?? null,
            'error' => $c->error,
        ] : null;

        return response()->json(['data' => [
            'station' => $report['station'],
            'interval_minutes' => $report['interval_minutes'],
            'checks' => $report['checks'],
            'first_check_at' => $report['first_check_at'] ? Carbon::parse($report['first_check_at'])->toIso8601String() : null,
            'last_check' => $check($report['last_check']),
            'changes' => $report['changes']->map($check)->values(),
        ]]);
    }

    /**
     * POST /admin/sync/kci — "Sync dari KCI": fetch the configured stations' timetable and
     * train stops from KCI (through the kci-fetch sidecar) into this database. The queue
     * worker runs it in the background; progress is on the sync log.
     */
    public function kci(Request $request, AutoSyncService $sync): JsonResponse
    {
        // KCI blocked this server recently: retrying now only prolongs the block.
        if ($until = KciUrlClient::blockedUntil()) {
            $message = 'KCI sedang memblokir server ini. Sinkronisasi dijeda sampai '.$until->timezone(config('app.timezone'))->format('H:i').'.';

            return response()->json([
                'message' => $message,
                'errors' => ['kci' => [$message]],
                'blocked_until' => $until->toIso8601String(),
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $log = $sync->start('manual', $request->user()->id);

        if (! $log) {
            return response()->json(['message' => 'A synchronization is already in progress.'], Response::HTTP_CONFLICT);
        }

        return (new SyncLogResource($log->refresh()->load('triggeredBy')))
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }

    /**
     * POST /admin/sync/import — manual import of a pasted KCI API response (JSON).
     */
    public function import(Request $request, ManualImportService $import): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:schedules,train_stops,stations'],
            'station' => ['required_if:type,schedules', 'nullable', 'string', 'max:10'],
            'train' => ['nullable', 'string', 'alpha_num', 'max:20'],
            'json' => ['required', 'string', 'max:8000000'],
        ], [
            'station.required_if' => 'Pilih stasiun untuk jadwal ini.',
            'json.required' => 'Tempel JSON dari response API KCI.',
        ]);

        $payload = json_decode($validated['json'], true);

        if (! is_array($payload)) {
            return response()->json(['message' => 'JSON tidak valid.', 'errors' => ['json' => ['JSON tidak valid: '.json_last_error_msg().'.']]], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = match ($validated['type']) {
                'schedules' => $import->schedules($payload, $this->activeStation((string) $validated['station']), $request->user()->id),
                'train_stops' => $import->trainStops($payload, $validated['train'] ?? null),
                'stations' => $import->stations($payload, $request->user()->id),
            };
        } catch (KciApiException $e) {
            return response()->json(['message' => 'Data tidak dapat dibaca.', 'errors' => ['json' => [$e->getMessage()]]], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['data' => ['type' => $validated['type'], ...$result]]);
    }

    private function activeStation(string $code): Station
    {
        return Station::active()->where('code', strtoupper($code))->first()
            ?? abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Stasiun tidak ditemukan atau tidak aktif.');
    }
}
