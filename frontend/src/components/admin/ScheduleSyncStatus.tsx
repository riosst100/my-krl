"use client";

import { useEffect, useRef, useState } from "react";
import { SyncStatusBadge, TRIGGER_LABELS } from "@/components/admin/SyncStatusBadge";
import { Button, Card, Skeleton } from "@/components/ui";
import { useToast } from "@/components/ui/Toast";
import { getSyncLogs, triggerSync } from "@/lib/api/admin";
import { ApiError, errorMessage } from "@/lib/api/client";
import { formatDateLong, formatDateTimeLong, formatNumber, todayInJakarta } from "@/lib/format";
import { useApi } from "@/lib/hooks/useApi";

/**
 * Admin → Jadwal: when the schedules were last synced, plus a manual sync for
 * today or tomorrow. Calls `onSynced` when a sync it started has finished.
 */
export function ScheduleSyncStatus({ onSynced }: { onSynced: () => void }) {
  const { toast } = useToast();
  const [tick, setTick] = useState(0);
  const [starting, setStarting] = useState(false);
  const waitingFor = useRef<number | null>(null);

  const { data, loading, error } = useApi(`schedule-sync-status|${tick}`, () => getSyncLogs(1, "schedules"));
  const meta = data?.meta;
  const last = meta?.last_schedule_sync;
  const inProgress = meta?.in_progress ?? false;

  // Poll while a sync runs; refresh the schedule table once ours has finished.
  useEffect(() => {
    if (inProgress) {
      const id = setTimeout(() => setTick((t) => t + 1), 3000);
      return () => clearTimeout(id);
    }
    if (waitingFor.current !== null && last && last.id >= waitingFor.current && last.finished_at) {
      waitingFor.current = null;
      if (last.status === "success" || last.status === "partial") {
        toast(`Sinkronisasi selesai: ${formatNumber(last.records_processed)} jadwal.`, "success");
      } else {
        toast("Sinkronisasi gagal. Lihat pesan error.", "error");
      }
      onSynced();
    }
  }, [inProgress, last, onSynced, toast]);

  const start = async () => {
    setStarting(true);
    try {
      const log = await triggerSync();
      waitingFor.current = log.id;
      toast("Sinkronisasi jadwal dimulai.", "success");
      setTick((t) => t + 1);
    } catch (err) {
      toast(err instanceof ApiError && err.status === 409 ? "Sinkronisasi lain sedang berjalan." : errorMessage(err), "error");
    } finally {
      setStarting(false);
    }
  };

  const today = todayInJakarta();
  const syncedDate = last?.meta?.from;
  const scheduledOffset = meta?.schedule_sync_day_offset ?? 1;

  return (
    <Card className="mb-6 flex flex-col gap-4 p-5 lg:flex-row lg:items-center lg:justify-between">
      <section aria-labelledby="schedule-sync-title" className="min-w-0">
        <div className="flex flex-wrap items-center gap-2">
          <h2 id="schedule-sync-title" className="font-semibold text-ink">
            Terakhir sync
          </h2>
          {last && <SyncStatusBadge status={last.status} />}
        </div>

        {loading && !data ? (
          <Skeleton className="mt-2 h-5 w-80" />
        ) : error ? (
          <p className="mt-1 text-sm text-red-700">Status sinkronisasi tidak dapat dimuat.</p>
        ) : last ? (
          <>
            <p className="mt-1 text-sm text-ink">
              <time dateTime={last.finished_at ?? last.started_at ?? last.created_at ?? undefined}>
                {formatDateTimeLong(last.finished_at ?? last.started_at ?? last.created_at)}
              </time>
              <span className="text-muted">
                {" "}
                · {TRIGGER_LABELS[last.trigger] ?? last.trigger}
                {last.triggered_by ? ` oleh ${last.triggered_by.name}` : ""}
              </span>
            </p>
            {(last.status === "success" || last.status === "partial") && (
              <p className="mt-1 text-sm text-muted">
                {formatNumber(last.records_processed)} jadwal
                {syncedDate ? ` untuk ${formatDateLong(syncedDate)}${syncedDate === today ? " (hari ini)" : ""}` : ""}
              </p>
            )}
            {last.error_message && <p className="mt-1 break-all text-sm text-red-700">{last.error_message}</p>}
            {last.status !== "success" && last.status !== "queued" && last.status !== "running" && (
              <p className="mt-1 text-sm text-muted">
                Terakhir berhasil:{" "}
                {meta?.last_successful_schedule_sync ? formatDateTimeLong(meta.last_successful_schedule_sync.finished_at) : "belum pernah"}
              </p>
            )}
          </>
        ) : (
          <p className="mt-1 text-sm text-muted">Belum pernah disinkronkan.</p>
        )}

        {meta && (
          <p className="mt-2 text-xs text-muted">
            Sync otomatis setiap hari pukul 00:00 & 04:00 · berikutnya {formatDateTimeLong(meta.next_schedule_sync)} (untuk jadwal{" "}
            {scheduledOffset === 1 ? "besok" : "hari berjalan"})
          </p>
        )}
      </section>

      <div className="flex shrink-0">
        <Button onClick={start} loading={starting} disabled={inProgress} className="h-11">
          {inProgress ? "Sinkronisasi berjalan…" : "Sync Jadwal Sekarang"}
        </Button>
      </div>
    </Card>
  );
}
