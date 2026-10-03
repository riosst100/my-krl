<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\SyncStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SyncLogResource;
use App\Models\SyncLog;
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
            ],
        ]);
    }

    public function show(SyncLog $syncLog): SyncLogResource
    {
        return new SyncLogResource($syncLog->load('triggeredBy'));
    }

    private function nextDailyRun(): Carbon
    {
        [$hour, $minute] = array_map('intval', explode(':', config('kci.sync_time')));
        $run = now()->setTime($hour, $minute);

        return $run->isPast() ? $run->addDay() : $run;
    }

    /**
     * Queues a sync; the queue worker runs it in the background.
     */
    public function store(Request $request, ScheduleSyncService $sync): JsonResponse
    {
        $log = $sync->queueManualSync($request->user());

        if (! $log) {
            return response()->json(['message' => 'A synchronization is already in progress.'], Response::HTTP_CONFLICT);
        }

        return (new SyncLogResource($log->refresh()->load('triggeredBy')))
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }
}
