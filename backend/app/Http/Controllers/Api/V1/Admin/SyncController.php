<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\SyncStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SyncLogResource;
use App\Models\KciTimetableCheck;
use App\Models\SyncLog;
use App\Services\KciTimetableWatchService;
use App\Services\ScheduleSyncService;
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

        $last = SyncLog::with('triggeredBy')->where('type', SyncLog::TYPE_KCI_SCHEDULES)->latest('id')->first();
        $lastSuccess = SyncLog::where('type', SyncLog::TYPE_KCI_SCHEDULES)
            ->whereIn('status', [SyncStatus::Success, SyncStatus::Partial])
            ->latest('finished_at')
            ->first();

        return SyncLogResource::collection($logs)->additional([
            'meta' => [
                'in_progress' => SyncLog::inProgress(SyncLog::TYPE_KCI_SCHEDULES)->exists(),
                'last_schedule_sync' => $last ? new SyncLogResource($last) : null,
                'last_successful_schedule_sync' => $lastSuccess ? new SyncLogResource($lastSuccess) : null,
                'next_schedule_sync' => $this->nextDailyRun()->toIso8601String(),
                // Which service day the scheduled run stores (1 = tomorrow).
                'schedule_sync_day_offset' => (int) config('kci.sync_day_offset'),
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

    private function nextDailyRun(): Carbon
    {
        $runs = collect(config('kci.sync_times'))->map(function (string $time) {
            [$hour, $minute] = array_map('intval', explode(':', $time));
            $run = now()->setTime($hour, $minute);

            return $run->isPast() ? $run->addDay() : $run;
        });

        return $runs->sort()->first() ?? now()->addDay()->startOfDay();
    }

    /**
     * Queues a sync; the queue worker runs it in the background.
     */
    public function store(Request $request, ScheduleSyncService $sync): JsonResponse
    {
        // 0 = today, 1 = tomorrow.
        $validated = $request->validate(['day_offset' => ['sometimes', 'integer', 'in:0,1']]);

        $log = $sync->queueManualSync($request->user(), now()->addDays((int) ($validated['day_offset'] ?? 0)));

        if (! $log) {
            return response()->json(['message' => 'A synchronization is already in progress.'], Response::HTTP_CONFLICT);
        }

        return (new SyncLogResource($log->refresh()->load('triggeredBy')))
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }
}
