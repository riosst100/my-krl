"use client";

import Link from "next/link";
import { SyncStatusBadge } from "@/components/admin/SyncStatusBadge";
import { Card, Skeleton } from "@/components/ui";
import { getSyncLogs } from "@/lib/api/admin";
import { formatDateLong, formatDateTimeLong, formatNumber } from "@/lib/format";
import { useApi } from "@/lib/hooks/useApi";

/**
 * Admin → Jadwal: when the data was last synced from KCI and for which service
 * date. Syncing itself lives in Admin → Sinkronisasi.
 */
export function ScheduleSyncStatus() {
  const { data, loading, error } = useApi("schedule-sync-status", () =>
    getSyncLogs(1, "schedules"),
  );
  const meta = data?.meta;
  const last = meta?.last_sync;
  const ok = last?.status === "success" || last?.status === "partial";

  return (
    <Card className="mb-6 flex flex-col gap-3 p-5 sm:flex-row sm:items-center sm:justify-between">
      <section aria-labelledby="schedule-sync-title" className="min-w-0">
        <div className="flex flex-wrap items-center gap-2">
          <h2 id="schedule-sync-title" className="font-semibold text-ink">
            Terakhir sync dari KCI
          </h2>
          {last && <SyncStatusBadge status={last.status} />}
        </div>

        {loading && !data ? (
          <Skeleton className="mt-2 h-5 w-72" />
        ) : error ? (
          <p className="mt-1 text-sm text-red-700">
            Status sinkronisasi tidak dapat dimuat.
          </p>
        ) : last ? (
          <>
            <p className="mt-1 text-sm text-ink">
              <time
                dateTime={
                  last.finished_at ??
                  last.started_at ??
                  last.created_at ??
                  undefined
                }
              >
                {formatDateTimeLong(
                  last.finished_at ?? last.started_at ?? last.created_at,
                )}
              </time>
            </p>
            {ok && (
              <p className="mt-1 text-sm text-muted">
                {formatNumber(last.records_processed)} jadwal
                {last.meta?.from
                  ? ` untuk ${formatDateLong(last.meta.from)}`
                  : ""}
              </p>
            )}
            {last.error_message && (
              <p className="mt-1 break-words text-sm text-red-700">
                {last.error_message}
              </p>
            )}
          </>
        ) : (
          <p className="mt-1 text-sm text-muted">
            Belum pernah disinkronkan.
          </p>
        )}
      </section>

      <Link
        href="/admin/sync/jadwal"
        className="shrink-0 text-sm font-semibold text-brand-600 hover:underline"
      >
        Sync Data →
      </Link>
    </Card>
  );
}
