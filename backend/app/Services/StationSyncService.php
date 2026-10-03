<?php

namespace App\Services;

use App\Enums\SyncStatus;
use App\Jobs\SyncKciStationsJob;
use App\Models\Setting;
use App\Models\Station;
use App\Models\SyncLog;
use App\Models\User;
use App\Services\Concerns\FinishesSyncLog;
use App\Services\Kci\Clients\KciUrlClient;
use App\Services\Kci\Data\KciStation;
use App\Services\Kci\Exceptions\KciApiException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Station master data from KCI. Stations rarely change, so this runs monthly
 * (and on demand from the admin panel), separately from the daily schedule sync.
 */
class StationSyncService
{
    use FinishesSyncLog;

    private const LOCK = 'kci-station-sync';

    public function __construct(
        private readonly KciService $kci,
        private readonly KciUrlClient $stationsApi,
    ) {}

    /**
     * The Stations API URL in effect: the admin setting if saved, otherwise
     * KCI_STATIONS_API_URL. Empty string = use the KCI client's station list.
     */
    public function stationsApiUrl(): string
    {
        return trim((string) Setting::value(Setting::STATIONS_API_URL, (string) config('kci.stations_api_url')));
    }

    public function createLog(string $trigger, ?int $userId = null, SyncStatus $status = SyncStatus::Queued): SyncLog
    {
        $url = $this->stationsApiUrl();

        return SyncLog::create([
            'type' => SyncLog::TYPE_KCI_STATIONS,
            'status' => $status,
            'trigger' => $trigger,
            'triggered_by' => $userId,
            'source' => $url !== '' ? 'http' : $this->kci->client()->name(),
            'meta' => ['url' => $url !== '' ? $url : null],
        ]);
    }

    /**
     * @return Collection<int, KciStation>
     *
     * @throws KciApiException
     */
    public function fetchStations(?string $url = null): Collection
    {
        return $this->fetchStationList($url)['stations'];
    }

    /**
     * @return array{stations: Collection<int, KciStation>, areas: array<int, string>}
     *
     * @throws KciApiException
     */
    public function fetchStationList(?string $url = null): array
    {
        $url ??= $this->stationsApiUrl();

        return $url !== ''
            ? $this->kci->parseStationList($this->stationsApi->fetch($url, config('kci.stations_api_token')))
            : $this->kci->getStationList();
    }

    /**
     * Dry run for the admin "Tes URL" button: fetch + parse, no database writes.
     *
     * @return array{ok: bool, url: string, count: int, sample: list<array<string, mixed>>, error: ?string}
     */
    public function preview(string $url): array
    {
        try {
            $stations = $this->fetchStations($url);

            return [
                'ok' => $stations->isNotEmpty(),
                'url' => $url,
                'count' => $stations->count(),
                'sample' => $stations->take(5)->map(fn (KciStation $s) => [
                    'code' => $s->code,
                    'name' => $s->name,
                    'enabled' => $s->enabled,
                    'operational_area' => $s->operationalArea,
                ])->all(),
                'error' => $stations->isEmpty() ? 'The station list is empty.' : null,
            ];
        } catch (KciApiException $e) {
            return ['ok' => false, 'url' => $url, 'count' => 0, 'sample' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Returns null when a station sync is already queued or running.
     */
    public function queueManualSync(User $admin): ?SyncLog
    {
        return Cache::lock(self::LOCK.':queue', 10)->block(5, function () use ($admin) {
            if (SyncLog::inProgress(SyncLog::TYPE_KCI_STATIONS)->exists()) {
                return null;
            }

            $log = $this->createLog('manual', $admin->id);
            SyncKciStationsJob::dispatch($log->id);

            return $log;
        });
    }

    public function run(SyncLog $log): SyncLog
    {
        $lock = Cache::lock(self::LOCK, 600);

        if (! $lock->get()) {
            return $this->finish($log, SyncStatus::Failed, 'Another station synchronization is already running.');
        }

        $log->update(['status' => SyncStatus::Running, 'started_at' => now()]);

        try {
            $list = $this->fetchStationList($log->meta['url'] ?? '');
            $result = $this->import($list['stations']);

            if ($list['areas'] !== []) {
                Setting::put(Setting::OPERATIONAL_AREAS, json_encode(
                    array_replace(Setting::operationalAreas(), $list['areas']),
                    JSON_FORCE_OBJECT | JSON_UNESCAPED_UNICODE,
                ));
            }

            $log->fill([
                'records_processed' => $result['total'],
                'stations_processed' => $result['total'],
                'meta' => [
                    ...($log->meta ?? []),
                    'created' => $result['created'],
                    'areas' => $list['areas'],
                    'removed_area_rows' => $result['removed'],
                    'updated' => $result['updated'],
                    'unchanged' => $result['total'] - $result['created'] - $result['updated'],
                    // In our database but no longer published by KCI. Kept, never deleted automatically.
                    'missing_from_kci' => $result['missing'],
                ],
            ]);

            return $this->finish($log, SyncStatus::Success);
        } catch (Throwable $e) {
            Log::error('KCI station sync failed', ['log_id' => $log->id, 'exception' => $e]);

            return $this->finish($log, SyncStatus::Failed, $e instanceof KciApiException
                ? $e->getMessage()
                : 'Unexpected error: '.class_basename($e).': '.$e->getMessage());
        } finally {
            $lock->release();
        }
    }

    /**
     * Upserts KCI stations. New stations take KCI's enabled flag as their
     * initial is_active; afterwards is_active belongs to the admin.
     *
     * @param  Collection<int, KciStation>  $stations
     * @return array{total: int, created: int, updated: int, missing: list<string>, removed: list<string>}
     */
    public function import(Collection $stations): array
    {
        if ($stations->isEmpty()) {
            throw KciApiException::invalidResponse('station list is empty');
        }

        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($stations, &$created, &$updated) {
            foreach ($stations as $data) {
                $station = Station::firstOrNew(['code' => $data->code]);
                $station->name = Str::title(Str::lower($data->name));
                $station->kci_enabled = $data->enabled;
                $station->operational_area = $data->operationalArea;

                if (! $station->exists) {
                    $station->is_active = $data->enabled;
                    $station->slug = $this->uniqueSlug($station->name, $data->code);
                    $created++;
                } elseif ($station->isDirty()) {
                    $updated++;
                }

                $station->synced_at = now();
                $station->save();
            }
        });

        // Clean up area header rows ("WIL0 / AREA JABODETABEK") saved by older syncs.
        $removed = Station::where(fn ($q) => $q->where('code', 'like', 'WIL%')->orWhere('name', 'ilike', 'area %'))
            ->whereDoesntHave('schedules')
            ->get()
            ->filter(fn (Station $s) => KciService::isAreaHeader($s->code, $s->name))
            ->each->delete()
            ->pluck('code')
            ->all();

        $missing = Station::whereNotIn('code', $stations->pluck('code'))->orderBy('code')->pluck('code')->all();

        return ['total' => $stations->count(), 'created' => $created, 'updated' => $updated, 'missing' => $missing, 'removed' => $removed];
    }

    private function uniqueSlug(string $name, string $code): string
    {
        $slug = Str::slug($name);

        return Station::where('slug', $slug)->exists() ? Str::slug("{$name}-{$code}") : $slug;
    }
}
