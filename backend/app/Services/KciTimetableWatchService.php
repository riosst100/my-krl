<?php

namespace App\Services;

use App\Models\KciTimetableCheck;
use App\Services\Kci\Clients\KciUrlClient;
use App\Services\Kci\Data\KciSchedule;
use App\Services\Kci\Exceptions\KciApiException;
use Illuminate\Support\Collection;

/**
 * Fingerprints KCI's current timetable for one station at regular intervals.
 * KCI's schedule API is undated, so the only way to learn *when* KCI switches
 * to the next day's timetable is to watch the content change.
 */
class KciTimetableWatchService
{
    public function __construct(
        private readonly KciService $kci,
        private readonly KciUrlClient $urlClient,
        private readonly ScheduleSyncService $scheduleSync,
    ) {}

    public function watchedStation(): string
    {
        return strtoupper((string) (config('kci.watch_station') ?: (config('kci.sync_stations')[0] ?? 'THB')));
    }

    public function check(?string $stationCode = null): KciTimetableCheck
    {
        $station = strtoupper($stationCode ?? $this->watchedStation());
        $url = $this->scheduleSync->schedulesApiUrl();
        $previous = KciTimetableCheck::where('station_code', $station)->where('ok', true)->latest('checked_at')->latest('id')->first();

        try {
            if ($url === '') {
                throw KciApiException::invalidResponse('Schedules API URL is empty: nothing to watch');
            }

            $schedules = $this->kci->parseSchedules(
                $this->urlClient->fetch(ScheduleSyncService::urlForStation($url, $station), config('kci.schedules_api_token')),
                "schedules of {$station}",
            )->sortBy('departureTime')->values();
        } catch (KciApiException $e) {
            return KciTimetableCheck::create([
                'station_code' => $station,
                'checked_at' => now(),
                'ok' => false,
                'error' => $e->getMessage(),
            ]);
        }

        $rows = $this->rows($schedules);
        $hash = sha1(implode("\n", array_keys($rows)));
        $changed = $previous !== null && $previous->content_hash !== $hash;

        $check = KciTimetableCheck::create([
            'station_code' => $station,
            'checked_at' => now(),
            'ok' => true,
            'trains' => $schedules->count(),
            'first_departure' => $schedules->first() ? substr($schedules->first()->departureTime, 0, 5) : null,
            'last_departure' => $schedules->last() ? substr($schedules->last()->departureTime, 0, 5) : null,
            'content_hash' => $hash,
            'changed' => $changed,
            // Keep this check's rows so the next change can be described.
            'diff' => [
                'rows' => array_values($rows),
                'summary' => $changed ? $this->describeChange($previous, $rows) : null,
            ],
        ]);

        // Keep the table small: rows are only needed for the latest check.
        if ($previous) {
            $previous->update(['diff' => array_filter(['summary' => $previous->diff['summary'] ?? null])]);
        }

        KciTimetableCheck::where('checked_at', '<', now()->subDays(config('kci.watch_retention_days')))->delete();

        return $check;
    }

    /**
     * One fingerprint row per train, keyed by the row itself (stable order).
     *
     * @param  Collection<int, KciSchedule>  $schedules
     * @return array<string, string>
     */
    private function rows(Collection $schedules): array
    {
        $rows = $schedules
            ->map(fn (KciSchedule $s) => implode('|', [$s->trainNumber, substr($s->departureTime, 0, 5), $s->destination, substr((string) $s->destinationArrivalTime, 0, 5)]))
            ->sort()
            ->values()
            ->all();

        return array_combine($rows, $rows);
    }

    /**
     * @param  array<string, string>  $rows
     * @return array{added: int, removed: int, trains_before: int|null, trains_after: int, sample_added: list<string>, sample_removed: list<string>}
     */
    private function describeChange(KciTimetableCheck $previous, array $rows): array
    {
        $before = array_flip($previous->diff['rows'] ?? []);
        $added = array_values(array_diff_key($rows, $before));
        $removed = array_keys(array_diff_key($before, $rows));

        return [
            'added' => count($added),
            'removed' => count($removed),
            'trains_before' => $previous->trains,
            'trains_after' => count($rows),
            'sample_added' => array_slice($added, 0, 5),
            'sample_removed' => array_slice($removed, 0, 5),
        ];
    }

    /**
     * Summary for the admin panel.
     *
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $station = $this->watchedStation();
        $base = fn () => KciTimetableCheck::where('station_code', $station);

        return [
            'station' => $station,
            'interval_minutes' => (int) config('kci.watch_every_minutes'),
            'checks' => $base()->count(),
            'first_check_at' => $base()->min('checked_at'),
            'last_check' => $base()->latest('checked_at')->latest('id')->first(),
            'changes' => $base()->where('changed', true)->latest('checked_at')->limit(30)->get(),
        ];
    }
}
