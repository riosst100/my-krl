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
 * Starting "Sync dari KCI" (SyncKciJob), by hand or at the configured times of
 * day (kci:auto-sync, every minute from the scheduler).
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
     * Queues a sync unless one is already queued or running.
     */
    public function start(string $trigger, ?int $userId): ?SyncLog
    {
        return Cache::lock('kci-sync:queue', 10)->block(5, function () use ($trigger, $userId) {
            if (SyncLog::inProgress(SyncLog::TYPE_KCI_SCHEDULES)->exists()) {
                return null;
            }

            $log = $this->direct->createLog($userId, $trigger);
            SyncKciJob::dispatch($log->id);

            return $log;
        });
    }

    /**
     * Called every minute by the scheduler: starts the sync when a configured
     * time is due. A run that the scheduler missed (container restart) still
     * starts up to KCI_AUTO_SYNC_GRACE_MINUTES late; each time slot runs once.
     */
    public function runDue(?CarbonInterface $now = null): ?SyncLog
    {
        $now = CarbonImmutable::instance($now ?? now());
        Cache::put(self::HEARTBEAT, $now->toIso8601String(), now()->addDay());

        $slot = $this->dueSlot($now);

        if ($slot === null || ! Cache::add('kci-auto-sync:slot:'.$slot->format('Y-m-d H:i'), true, now()->addDays(2))) {
            return null;
        }

        $log = $this->start('schedule', null);

        if ($log === null) {
            Log::warning('Automatic sync skipped: another synchronization is in progress', ['slot' => $slot->format('Y-m-d H:i')]);
        } else {
            Log::info('Automatic sync started', ['slot' => $slot->format('Y-m-d H:i'), 'log_id' => $log->id]);
        }

        return $log;
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
