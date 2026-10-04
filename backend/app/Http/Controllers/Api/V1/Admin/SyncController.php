<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\SyncStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SyncLogResource;
use App\Models\KciTimetableCheck;
use App\Models\SyncLog;
use App\Services\KciTimetableWatchService;
use App\Jobs\PushToProdJob;
use App\Models\Station;
use App\Services\Kci\Exceptions\KciApiException;
use App\Services\ManualImportService;
use App\Services\ProdPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class SyncController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'type' => ['sometimes', 'nullable', 'in:schedules,stations,push'],
        ]);

        $type = match ($request->input('type')) {
            'schedules' => SyncLog::TYPE_KCI_SCHEDULES,
            'stations' => SyncLog::TYPE_KCI_STATIONS,
            'push' => SyncLog::TYPE_PROD_PUSH,
            default => null,
        };

        $logs = SyncLog::with('triggeredBy')
            ->when($type, fn ($q) => $q->where('type', $type))
            ->latest('id')
            ->paginate($request->integer('per_page', 20));

        // "The sync": on the local machine the push to prod, on the server the data received from local.
        $local = ProdPushService::configured();
        $syncs = fn () => SyncLog::query()->when(
            $local,
            fn ($q) => $q->where('type', SyncLog::TYPE_PROD_PUSH),
            fn ($q) => $q->where('type', SyncLog::TYPE_KCI_SCHEDULES)->where('trigger', 'ingest'),
        );
        $last = $syncs()->with('triggeredBy')->latest('id')->first();
        $lastSuccess = $syncs()->whereIn('status', [SyncStatus::Success, SyncStatus::Partial])->latest('finished_at')->first();

        return SyncLogResource::collection($logs)->additional([
            'meta' => [
                // local = can push to prod (PROD_SYNC_URL + PROD_SYNC_TOKEN set); prod = only receives.
                'mode' => $local ? 'local' : 'prod',
                'push_target' => ProdPushService::targetHost(),
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
     * POST /admin/sync/prod — "Sync Data to Prod": fetch from KCI locally and push to production.
     * The queue worker runs it in the background; progress is on the sync log.
     */
    public function push(Request $request, ProdPushService $push): JsonResponse
    {
        // fetch=false: send the data already in the local database (e.g. after a manual JSON import).
        $validated = $request->validate(['fetch' => ['sometimes', 'boolean']]);
        $fetch = (bool) ($validated['fetch'] ?? true);

        if (! ProdPushService::configured()) {
            return response()->json(['message' => 'Server ini tidak dikonfigurasi untuk mengirim data (PROD_SYNC_URL dan PROD_SYNC_TOKEN belum diatur).'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $log = Cache::lock('prod-push:queue', 10)->block(5, function () use ($request, $push, $fetch) {
            if (SyncLog::inProgress(SyncLog::TYPE_PROD_PUSH)->exists()) {
                return null;
            }

            $log = $push->createLog($request->user()->id, fetch: $fetch);
            PushToProdJob::dispatch($log->id);

            return $log;
        });

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
