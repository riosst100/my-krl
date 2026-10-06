"use client";

import { useState, type FormEvent } from "react";
import { SyncStatusBadge } from "@/components/admin/SyncStatusBadge";
import { Alert, Badge, Button, Card, Skeleton, controlClass, cx } from "@/components/ui";
import { useToast } from "@/components/ui/Toast";
import {
  getAutoSyncSetting,
  resetAutoSyncTimes,
  saveAutoSyncTimes,
  type KciSyncType,
} from "@/lib/api/admin";
import { ApiError, errorMessage } from "@/lib/api/client";
import { formatDateTimeLong } from "@/lib/format";
import { useApi } from "@/lib/hooks/useApi";

const sameList = (a: string[], b: string[]) =>
  a.length === b.length && a.every((t, i) => t === b[i]);

/** In run order: stations before schedules before the trains in them. */
const TYPES: { type: KciSyncType; label: string }[] = [
  { type: "stations", label: "Stasiun" },
  { type: "schedules", label: "Jadwal" },
  { type: "trains", label: "Kereta" },
];

const typeLabels = (types: KciSyncType[]) =>
  TYPES.filter((t) => types.includes(t.type))
    .map((t) => t.label)
    .join(" → ");

/**
 * Admin → Sinkronisasi: times of day at which the sync runs by itself, and
 * which kinds (stations, schedules, trains) it runs.
 */
export function AutoSyncPanel() {
  const { toast } = useToast();
  const setting = useApi("auto-sync-setting", () => getAutoSyncSetting());

  // null = untouched: show the saved times.
  const [draft, setDraft] = useState<string[] | null>(null);
  const [typesDraft, setTypesDraft] = useState<KciSyncType[] | null>(null);
  const [newTime, setNewTime] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string>();

  const current = setting.data;
  const times = draft ?? current?.times ?? [];
  const types = typesDraft ?? current?.types ?? [];
  const dirty = current
    ? !sameList(times, current.times) || !sameList(types, current.types)
    : false;

  const toggleType = (type: KciSyncType) => {
    setTypesDraft(
      TYPES.map((t) => t.type).filter((t) =>
        t === type ? !types.includes(t) : types.includes(t),
      ),
    );
    setError(undefined);
  };

  const add = (e: FormEvent) => {
    e.preventDefault();
    if (!/^([01]\d|2[0-3]):[0-5]\d$/.test(newTime)) {
      setError("Isi jam dengan format HH:MM.");
      return;
    }
    setDraft([...new Set([...times, newTime])].sort());
    setNewTime("");
    setError(undefined);
  };

  const remove = (time: string) => {
    setDraft(times.filter((t) => t !== time));
    setError(undefined);
  };

  const run = async (action: () => Promise<unknown>, success: string) => {
    setSaving(true);
    setError(undefined);
    try {
      await action();
      setDraft(null);
      setTypesDraft(null);
      await setting.reload();
      toast(success, "success");
    } catch (err) {
      setError(
        err instanceof ApiError && err.isValidation
          ? (err.field("times") ??
              err.field("times.0") ??
              err.field("types") ??
              err.message)
          : errorMessage(err),
      );
    } finally {
      setSaving(false);
    }
  };

  return (
    <Card className="mb-6 p-5">
      <div className="flex flex-wrap items-center gap-2">
        <h2 className="font-semibold text-ink">Sync otomatis</h2>
        {current && (
          <Badge tone={current.times.length > 0 ? "success" : "neutral"}>
            {current.times.length > 0 ? "Aktif" : "Nonaktif"}
          </Badge>
        )}
        {current && !current.is_default && (
          <Badge tone="info">Diatur admin</Badge>
        )}
      </div>
      <p className="mt-1 text-sm text-muted">
        Menjalankan sync yang dicentang setiap hari pada jam yang dipilih (
        {current?.timezone ?? "Asia/Jakarta"}), berurutan stasiun → jadwal →
        kereta. Tombol sync manual di Sync Stasiun, Jadwal, dan Kereta tetap
        bisa dipakai kapan saja.
      </p>

      {setting.loading && !current ? (
        <Skeleton className="mt-4 h-20 w-full" />
      ) : setting.error && !current ? (
        <div className="mt-4">
          <Alert>Pengaturan sync otomatis tidak dapat dimuat.</Alert>
        </div>
      ) : (
        <>
          {current && current.times.length > 0 && !current.scheduler_running && (
            <div className="mt-3">
              <Alert>
                Scheduler tidak berjalan
                {current.scheduler_seen_at
                  ? ` (terakhir aktif ${formatDateTimeLong(current.scheduler_seen_at)})`
                  : ""}
                , jadi sync otomatis tidak akan jalan. Jalankan{" "}
                <code className="font-mono">php artisan schedule:work</code>{" "}
                (container <code className="font-mono">scheduler</code>) atau
                cron <code className="font-mono">schedule:run</code> tiap menit.
              </Alert>
            </div>
          )}

          <fieldset className="mt-4">
            <legend className="mb-1.5 text-sm font-medium text-slate-700">
              Yang disinkronkan otomatis
            </legend>
            <div className="flex flex-wrap gap-x-5 gap-y-2">
              {TYPES.map((t) => (
                <label
                  key={t.type}
                  className="flex min-h-10 cursor-pointer items-center gap-2 text-sm text-ink"
                >
                  <input
                    type="checkbox"
                    checked={types.includes(t.type)}
                    onChange={() => toggleType(t.type)}
                    className="h-4 w-4 rounded accent-brand-600"
                  />
                  {t.label}
                </label>
              ))}
            </div>
            {types.length === 0 && (
              <p className="mt-1 text-xs text-red-700">
                Pilih minimal 1 jenis sync.
              </p>
            )}
          </fieldset>

          <ul className="mt-4 flex flex-wrap gap-1.5" aria-label="Jadwal sync">
            {times.length === 0 && (
              <li className="text-sm text-muted">Belum ada jadwal.</li>
            )}
            {times.map((time) => (
              <li key={time}>
                <button
                  type="button"
                  onClick={() => remove(time)}
                  title="Klik untuk menghapus"
                  aria-label={`Hapus jadwal ${time}`}
                  className="tabular inline-flex items-center gap-1.5 rounded-full bg-brand-50 px-3 py-1.5 text-sm font-semibold text-brand-700 transition-colors hover:bg-brand-100"
                >
                  {time}
                  <span aria-hidden="true">×</span>
                </button>
              </li>
            ))}
          </ul>

          <form onSubmit={add} className="mt-3 flex flex-wrap items-end gap-2">
            <label className="block">
              <span className="mb-1 block text-sm font-medium text-slate-700">
                Tambah jam
              </span>
              <input
                type="time"
                value={newTime}
                onChange={(e) => setNewTime(e.target.value)}
                className={cx(controlClass, "tabular w-36")}
              />
            </label>
            <Button type="submit" variant="secondary" disabled={!newTime}>
              Tambah
            </Button>
          </form>

          {error && (
            <div className="mt-3">
              <Alert>{error}</Alert>
            </div>
          )}

          <div className="mt-4 flex flex-wrap items-center gap-2">
            <Button
              onClick={() =>
                run(
                  () => saveAutoSyncTimes(times, types),
                  times.length > 0
                    ? "Jadwal sync otomatis disimpan."
                    : "Sync otomatis dinonaktifkan.",
                )
              }
              loading={saving}
              disabled={!dirty || types.length === 0}
            >
              Simpan
            </Button>
            {dirty && (
              <Button
                variant="ghost"
                onClick={() => {
                  setDraft(null);
                  setTypesDraft(null);
                }}
                disabled={saving}
              >
                Batalkan perubahan
              </Button>
            )}
            {current && !current.is_default && (
              <Button
                variant="ghost"
                onClick={() =>
                  run(() => resetAutoSyncTimes(), "Dikembalikan ke default (.env).")
                }
                disabled={saving}
              >
                Kembalikan ke default
              </Button>
            )}
          </div>

          {current && (
            <div className="mt-4 space-y-1 text-xs text-muted">
              {current.next_run_at && (
                <p>
                  Berikutnya:{" "}
                  <span className="font-medium text-slate-700">
                    {formatDateTimeLong(current.next_run_at)}
                  </span>
                </p>
              )}
              {current.last_run && (
                <p className="flex flex-wrap items-center gap-1.5">
                  Terakhir otomatis:{" "}
                  {formatDateTimeLong(
                    current.last_run.finished_at ??
                      current.last_run.started_at ??
                      current.last_run.created_at,
                  )}
                  <SyncStatusBadge status={current.last_run.status} />
                </p>
              )}
              <p>
                Default (.env):{" "}
                {current.default_times.length > 0
                  ? `${current.default_times.join(", ")} (${typeLabels(current.default_types)})`
                  : "nonaktif"}
                {current.updated_at &&
                  ` · diubah ${formatDateTimeLong(current.updated_at)}${current.updated_by ? ` oleh ${current.updated_by}` : ""}`}
              </p>
            </div>
          )}
        </>
      )}
    </Card>
  );
}
