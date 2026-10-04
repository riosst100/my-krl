"use client";

import { useState } from "react";
import { SyncStatusBadge } from "@/components/admin/SyncStatusBadge";
import { Alert, Button, Card, Skeleton } from "@/components/ui";
import { useToast } from "@/components/ui/Toast";
import { triggerPushToProd, type SyncLogsMeta } from "@/lib/api/admin";
import { ApiError, errorMessage } from "@/lib/api/client";
import type { SyncProgress } from "@/lib/api/types";
import { formatDateTimeLong, formatNumber } from "@/lib/format";

const STEPS: { phase: SyncProgress["phase"]; label: string }[] = [
  { phase: "fetch_schedules", label: "Ambil jadwal" },
  { phase: "fetch_stops", label: "Ambil pemberhentian" },
  { phase: "stations", label: "Kirim stasiun" },
  { phase: "push_schedules", label: "Kirim jadwal" },
  { phase: "push_stops", label: "Kirim pemberhentian" },
];

/**
 * Admin → Sinkronisasi. Local: "Sync Data to Prod" (fetch from KCI, push to the
 * production API) with a live progress bar. Server: shows what was received.
 */
export function ProdPushPanel({
  meta,
  onChanged,
}: {
  meta: SyncLogsMeta | undefined;
  onChanged: () => void;
}) {
  const { toast } = useToast();
  const [starting, setStarting] = useState(false);
  // Unchecked = send the data already stored locally (after a manual JSON import).
  const [fetchFirst, setFetchFirst] = useState(true);

  const last = meta?.last_sync ?? null;
  const running = meta?.in_progress ?? false;
  const progress = running ? last?.meta?.progress : undefined;

  const start = async () => {
    setStarting(true);
    try {
      await triggerPushToProd(fetchFirst);
      toast("Sync ke prod dimulai. Progres tampil di bawah.", "success");
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

  if (meta.mode === "prod") {
    return (
      <Card className="mb-6 p-5">
        <h2 className="font-semibold text-ink">Data dari lokal</h2>
        <p className="mt-1 text-sm text-muted">
          Server ini tidak mengambil data dari KCI. Jadwal dikirim dari komputer
          lokal lewat menu Sinkronisasi di panel admin lokal.
        </p>
        <LastResult
          log={last}
          lastSuccessAt={meta.last_successful_sync?.finished_at ?? null}
          received
        />
      </Card>
    );
  }

  return (
    <Card className="mb-6 p-5">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0">
          <h2 className="font-semibold text-ink">Sync Data ke Prod</h2>
          <p className="mt-1 text-sm text-muted">
            Mengambil jadwal dan pemberhentian kereta dari KCI di komputer ini,
            lalu mengirimnya ke server prod
            {meta?.push_target ? (
              <span className="font-medium text-slate-700">
                {" "}
                ({meta.push_target})
              </span>
            ) : null}
            . Stasiun yang disinkronkan sesuai pilihan di bawah.
          </p>
        </div>
        <Button
          onClick={start}
          loading={starting || running}
          disabled={!meta || running}
          className="h-11 shrink-0"
        >
          {running ? "Sinkronisasi berjalan…" : "Sync Data to Prod"}
        </Button>
      </div>

      <label className="mt-3 flex w-fit cursor-pointer items-start gap-2 text-[13px] text-slate-700">
        <input
          type="checkbox"
          checked={fetchFirst}
          onChange={(e) => setFetchFirst(e.target.checked)}
          disabled={running}
          className="mt-0.5 h-4 w-4 rounded accent-brand-600"
        />
        <span>
          Ambil data dari KCI dulu
          <span className="block text-xs text-muted">
            Hilangkan centang untuk mengirim data yang sudah ada di komputer ini
            (misalnya hasil import JSON).
          </span>
        </span>
      </label>

      {running && (
        <ProgressView progress={progress} fetched={last?.meta?.fetch} />
      )}
      {!running && (
        <LastResult
          log={last}
          lastSuccessAt={meta?.last_successful_sync?.finished_at ?? null}
        />
      )}
    </Card>
  );
}

function ProgressView({
  progress,
  fetched,
}: {
  progress: SyncProgress | undefined;
  fetched: boolean | undefined;
}) {
  const percent = progress?.percent ?? 0;
  const steps =
    fetched === false
      ? STEPS.filter((s) => !s.phase.startsWith("fetch_"))
      : STEPS;
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
        aria-label="Progres sync ke prod"
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
  received,
}: {
  log: SyncLogsMeta["last_sync"];
  lastSuccessAt: string | null;
  received?: boolean;
}) {
  if (!log) {
    return (
      <p className="mt-4 text-sm text-muted">
        {received
          ? "Belum ada data yang diterima dari lokal."
          : "Belum pernah sync ke prod."}
      </p>
    );
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
          {log.triggered_by && (
            <span className="text-muted"> · oleh {log.triggered_by.name}</span>
          )}
        </p>
      </div>
      {ok && (
        <p className="mt-1.5 text-sm text-slate-600">
          {formatNumber(log.records_processed)} jadwal ·{" "}
          {formatNumber(log.stations_processed)} stasiun
          {typeof log.meta?.trains === "number" &&
            ` · ${formatNumber(log.meta.trains)} kereta (${formatNumber(log.meta.stops ?? 0)} pemberhentian)`}
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
