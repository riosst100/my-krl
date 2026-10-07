<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\SyncStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SyncLogResource;
use App\Models\Station;
use App\Models\SyncLog;
use App\Services\Kci\Exceptions\KciApiException;
use App\Services\ManualImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class SyncController extends Controller
{
    /**
     * GET /admin/sync-logs?type= — the meta describes the syncs that bring data in
     * (krl-sync on Vercel through the ingest API, or a manual import) of that type;
     * without a type, of the schedules. This server never fetches from KCI itself.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'type' => ['sometimes', 'nullable', Rule::in(array_keys(SyncLog::KCI_TYPES))],
        ]);

        $type = SyncLog::KCI_TYPES[$request->input('type')] ?? null;

        $logs = SyncLog::with('triggeredBy')
            ->when($type, fn ($q) => $q->where('type', $type))
            ->latest('id')
            ->paginate($request->integer('per_page', 20));

        $syncs = fn () => SyncLog::query()->where('type', $type ?? SyncLog::TYPE_KCI_SCHEDULES)->whereIn('trigger', ['ingest', 'import']);
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
