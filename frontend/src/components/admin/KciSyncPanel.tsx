"use client";

import { useState } from "react";
import {
  SyncStatusBadge,
  TRIGGER_LABELS,
} from "@/components/admin/SyncStatusBadge";
import { Alert, Button, Card, Skeleton } from "@/components/ui";
import { useToast } from "@/components/ui/Toast";
import { triggerKciSync, type SyncLogsMeta } from "@/lib/api/admin";
import { ApiError, errorMessage } from "@/lib/api/client";
import type { SyncProgress } from "@/lib/api/types";
import { formatDateTimeLong, formatNumber } from "@/lib/format";

const STEPS: { phase: SyncProgress["phase"]; label: string }[] = [
  { phase: "fetch_schedules", label: "Ambil jadwal" },
  { phase: "fetch_stops", label: "Ambil pemberhentian" },
];

/**
 * Admin → Sinkronisasi → Sync Data: "Sync dari KCI" (fetch the selected stations'
 * timetable and train stops from KCI into the database) with a live progress bar.
 */
export function KciSyncPanel({
  meta,
  onChanged,
}: {
  meta: SyncLogsMeta | undefined;
  onChanged: () => void;
}) {
  const { toast } = useToast();
  const [starting, setStarting] = useState(false);

  const last = meta?.last_sync ?? null;
  const running = meta?.in_progress ?? false;
  const progress = running ? last?.meta?.progress : undefined;

  const start = async () => {
    setStarting(true);
    try {
      await triggerKciSync();
      toast("Sync dari KCI dimulai. Progres tampil di bawah.", "success");
      onChanged();
    } catch (err) {
      toast(
        err instanceof ApiError && err.status === 409
          ? "Sinkronisasi lain sedang berjalan."
          : errorMessage(err),
        "error",
      );
    } finally {
      setStarting(false);
    }
  };

  if (!meta) {
    return (
      <Card className="mb-6 p-5">
        <Skeleton className="h-6 w-48" />
        <Skeleton className="mt-3 h-12 w-full" />
      </Card>
    );
  }

  return (
    <Card className="mb-6 p-5">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0">
          <h2 className="font-semibold text-ink">Sync dari KCI</h2>
          <p className="mt-1 text-sm text-muted">
            Mengambil jadwal dan pemberhentian kereta langsung dari KCI.
            Stasiun yang disinkronkan sesuai pilihan di bawah.
          </p>
        </div>
        <Button
          onClick={start}
          loading={starting || running}
          disabled={running}
          className="h-11 shrink-0"
        >
          {running ? "Sinkronisasi berjalan…" : "Sync Sekarang"}
        </Button>
      </div>
      {running && <ProgressView progress={progress} />}
      {!running && (
        <LastResult
          log={last}
          lastSuccessAt={meta.last_successful_sync?.finished_at ?? null}
        />
      )}
    </Card>
  );
}

function ProgressView({ progress }: { progress: SyncProgress | undefined }) {
  const percent = progress?.percent ?? 0;
  const steps = STEPS;
  const current = steps.findIndex((s) => s.phase === progress?.phase);

  return (
    <div className="mt-5" aria-live="polite">
      <div className="flex items-baseline justify-between gap-3 text-sm">
        <p className="min-w-0 truncate font-medium text-ink">
          {progress?.label ?? "Menunggu antrean…"}
        </p>
        <p className="tabular shrink-0 font-bold text-brand-700">{percent}%</p>
      </div>
      <div
        role="progressbar"
        aria-valuemin={0}
        aria-valuemax={100}
        aria-valuenow={percent}
        aria-label="Progres sync dari KCI"
        className="mt-2 h-2.5 overflow-hidden rounded-full bg-slate-200"
      >
        <div
          className="h-full rounded-full bg-brand-600 transition-[width] duration-500"
          style={{ width: `${Math.max(percent, 2)}%` }}
        />
      </div>
      <p className="tabular mt-1.5 text-xs text-muted">
        {progress && progress.total > 0
          ? `${formatNumber(progress.done)} dari ${formatNumber(progress.total)}`
          : " "}
        {progress?.detail ? ` · ${progress.detail}` : ""}
      </p>

      <ol className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs">
        {steps.map((step, i) => (
          <li
            key={step.phase}
            aria-current={i === current ? "step" : undefined}
            className={
              i < current
                ? "text-emerald-700"
                : i === current
                  ? "font-semibold text-brand-700"
                  : "text-muted"
            }
          >
            {i < current ? "✓" : i + 1}. {step.label}
          </li>
        ))}
      </ol>
    </div>
  );
}

function LastResult({
  log,
  lastSuccessAt,
}: {
  log: SyncLogsMeta["last_sync"];
  lastSuccessAt: string | null;
}) {
  if (!log) {
    return <p className="mt-4 text-sm text-muted">Belum pernah sync dari KCI.</p>;
  }

  const ok = log.status === "success" || log.status === "partial";

  return (
    <div className="mt-4 rounded-xl border border-line bg-slate-50/60 p-3.5">
      <div className="flex flex-wrap items-center gap-2">
        <SyncStatusBadge status={log.status} />
        <p className="text-sm text-ink">
          <time dateTime={log.finished_at ?? log.created_at ?? undefined}>
            {formatDateTimeLong(
              log.finished_at ?? log.started_at ?? log.created_at,
            )}
          </time>
          <span className="text-muted">
            {" "}
            · {TRIGGER_LABELS[log.trigger] ?? log.trigger}
            {log.triggered_by && ` oleh ${log.triggered_by.name}`}
          </span>
        </p>
      </div>
      {ok && (
        <p className="mt-1.5 text-sm text-slate-600">
          {formatNumber(log.records_processed)} jadwal ·{" "}
          {formatNumber(log.stations_processed)} stasiun
          {log.meta?.train_stops &&
            ` · ${formatNumber(log.meta.train_stops.trains)} kereta (${formatNumber(log.meta.train_stops.stops)} pemberhentian)`}
        </p>
      )}
      {log.error_message && (
        <div className="mt-2">
          <Alert>{log.error_message}</Alert>
        </div>
      )}
      {!ok && lastSuccessAt && (
        <p className="mt-2 text-xs text-muted">
          Terakhir berhasil: {formatDateTimeLong(lastSuccessAt)}
        </p>
      )}
    </div>
  );
}
