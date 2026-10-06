<?php

namespace App\Services;

use App\Jobs\SyncKciJob;
use App\Models\Setting;
use App\Models\SyncLog;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Starting "Sync dari KCI" (SyncKciJob) for stations, schedules or train stops,
 * by hand or at the configured times of day (kci:auto-sync, every minute from
 * the scheduler) for the kinds chosen for the automatic sync.
 */
class AutoSyncService
{
    public const TIME_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';

    private const HEARTBEAT = 'kci-auto-sync:heartbeat';

    public function __construct(private readonly KciDirectSyncService $direct) {}

    /**
     * Configured times of day ("HH:MM", sorted): the admin setting if saved,
     * otherwise KCI_AUTO_SYNC_TIMES. Empty = no automatic sync.
     *
     * @return list<string>
     */
    public static function times(): array
    {
        return self::normalize(explode(',', (string) Setting::value(Setting::AUTO_SYNC_TIMES, (string) config('kci.auto_sync_times'))));
    }

    /**
     * Kinds the automatic sync runs (keys of SyncLog::KCI_TYPES, in run order):
     * the admin setting if saved, otherwise KCI_AUTO_SYNC_TYPES.
     *
     * @return list<string>
     */
    public static function types(): array
    {
        return self::normalizeTypes(explode(',', (string) Setting::value(Setting::AUTO_SYNC_TYPES, (string) config('kci.auto_sync_types'))));
    }

    /**
     * @param  iterable<mixed>  $types
     * @return list<string>
     */
    public static function normalizeTypes(iterable $types): array
    {
        $wanted = array_map(fn ($type) => strtolower(trim((string) $type)), [...$types]);

        return array_values(array_filter(array_keys(SyncLog::KCI_TYPES), fn ($type) => in_array($type, $wanted, true)));
    }

    /**
     * @param  iterable<mixed>  $times
     * @return list<string>
     */
    public static function normalize(iterable $times): array
    {
        $valid = [];

        foreach ($times as $time) {
            $time = trim((string) $time);
            // Accept "4:00" as "04:00".
            $time = preg_match('/^\d:\d\d$/', $time) ? "0{$time}" : $time;

            if (preg_match(self::TIME_PATTERN, $time)) {
                $valid[$time] = $time;
            }
        }

        ksort($valid);

        return array_values($valid);
    }

    /**
     * Queues a sync of one kind ("stations", "schedules" or "trains") unless one
     * of that kind is already queued or running. The single queue worker runs
     * queued syncs one after another, in the order they were started.
     */
    public function start(string $type, string $trigger, ?int $userId): ?SyncLog
    {
        $logType = SyncLog::KCI_TYPES[$type];

        return Cache::lock('kci-sync:queue', 10)->block(5, function () use ($logType, $trigger, $userId) {
            if (SyncLog::inProgress($logType)->exists()) {
                return null;
            }

            $log = $this->direct->createLog($logType, $userId, $trigger);
            SyncKciJob::dispatch($log->id);

            return $log;
        });
    }

    /**
     * Called every minute by the scheduler: starts the chosen kinds of sync when
     * a configured time is due. A run that the scheduler missed (container
     * restart) still starts up to KCI_AUTO_SYNC_GRACE_MINUTES late; each time
     * slot runs once.
     *
     * @return list<SyncLog> the syncs that were queued
     */
    public function runDue(?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now());
        Cache::put(self::HEARTBEAT, $now->toIso8601String(), now()->addDay());

        $slot = $this->dueSlot($now);

        if ($slot === null || ! Cache::add('kci-auto-sync:slot:'.$slot->format('Y-m-d H:i'), true, now()->addDays(2))) {
            return [];
        }

        $logs = [];

        foreach (self::types() as $type) {
            $log = $this->start($type, 'schedule', null);

            if ($log === null) {
                Log::warning('Automatic sync skipped: another synchronization of this kind is in progress', ['slot' => $slot->format('Y-m-d H:i'), 'type' => $type]);
            } else {
                Log::info('Automatic sync started', ['slot' => $slot->format('Y-m-d H:i'), 'type' => $type, 'log_id' => $log->id]);
                $logs[] = $log;
            }
        }

        return $logs;
    }

    public function dueSlot(CarbonImmutable $now): ?CarbonImmutable
    {
        $grace = max(0, (int) config('kci.auto_sync_grace_minutes'));
        // Times added or changed by the admin only count from the moment they were saved.
        $savedAt = Setting::find(Setting::AUTO_SYNC_TIMES)?->updated_at;
        $minute = $now->startOfMinute();

        foreach (self::times() as $time) {
            [$h, $m] = array_map('intval', explode(':', $time));

            // Yesterday's slot too, for a late run just after midnight.
            foreach ([$minute, $minute->subDay()] as $day) {
                $slot = $day->setTime($h, $m);

                if ($slot <= $minute && $slot->diffInMinutes($minute) <= $grace && ($savedAt === null || $slot >= $savedAt->copy()->startOfMinute())) {
                    return $slot;
                }
            }
        }

        return null;
    }

    public function nextRun(?CarbonInterface $now = null): ?CarbonImmutable
    {
        $now = CarbonImmutable::instance($now ?? now());
        $next = null;

        foreach (self::times() as $time) {
            [$h, $m] = array_map('intval', explode(':', $time));
            $slot = $now->setTime($h, $m);
            $slot = $slot <= $now ? $slot->addDay() : $slot;
            $next = $next === null || $slot < $next ? $slot : $next;
        }

        return $next;
    }

    /**
     * Last time the scheduler called runDue(), or null if it never did (scheduler not running).
     */
    public static function lastHeartbeat(): ?CarbonImmutable
    {
        $at = Cache::get(self::HEARTBEAT);

        return $at ? CarbonImmutable::parse($at) : null;
    }
}
