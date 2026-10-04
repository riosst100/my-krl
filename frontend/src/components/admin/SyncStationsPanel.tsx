"use client";

import { useMemo, useState } from "react";
import { Alert, Badge, Button, Card, Skeleton, TextField } from "@/components/ui";
import { useToast } from "@/components/ui/Toast";
import { ApiError, errorMessage } from "@/lib/api/client";
import { getAdminStations, getSyncStationsSetting, resetSyncStations, saveSyncStations } from "@/lib/api/admin";
import { formatDateTimeLong } from "@/lib/format";
import { useApi } from "@/lib/hooks/useApi";

const sameSet = (a: string[], b: string[]) => a.length === b.length && a.every((code) => b.includes(code));

/** Admin → Sinkronisasi: which stations' timetables the schedule sync fetches. */
export function SyncStationsPanel({ onChanged }: { onChanged: () => void }) {
  const { toast } = useToast();
  const setting = useApi("sync-stations-setting", () => getSyncStationsSetting());
  const stations = useApi("sync-stations-list", async () => (await getAdminStations({ status: "active", per_page: 200 })).data);

  // null = untouched: show the saved selection.
  const [draft, setDraft] = useState<string[] | null>(null);
  const [search, setSearch] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string>();

  const current = setting.data;
  const selected = draft ?? current?.stations ?? [];

  const visible = useMemo(() => {
    const term = search.trim().toLowerCase();
    const list = stations.data ?? [];
    return term ? list.filter((s) => s.name.toLowerCase().includes(term) || s.code.toLowerCase().includes(term)) : list;
  }, [stations.data, search]);

  const toggle = (code: string) => {
    setDraft((prev) => {
      const list = prev ?? current?.stations ?? [];
      return list.includes(code) ? list.filter((c) => c !== code) : [...list, code];
    });
    setError(undefined);
  };

  const run = async (action: () => Promise<unknown>, success: string) => {
    setSaving(true);
    setError(undefined);
    try {
      await action();
      setDraft(null);
      await setting.reload();
      toast(success, "success");
      onChanged();
    } catch (err) {
      setError(err instanceof ApiError && err.isValidation ? (err.field("stations") ?? err.field("stations.0") ?? err.message) : errorMessage(err));
    } finally {
      setSaving(false);
    }
  };

  const dirty = current ? !sameSet(selected, current.stations) : false;

  return (
    <Card className="mb-6 p-5">
      <div className="flex flex-wrap items-center gap-2">
        <h2 className="font-semibold text-ink">Stasiun yang disinkronkan</h2>
        {current && <Badge tone={current.is_default ? "neutral" : "info"}>{current.is_default ? "Default (.env)" : "Diatur admin"}</Badge>}
      </div>
      <p className="mt-1 text-sm text-muted">
        Jadwal hanya diambil untuk stasiun yang dipilih. Makin banyak stasiun, makin lama sinkronisasi dan makin banyak permintaan ke KCI.
      </p>

      {setting.loading && !current ? (
        <Skeleton className="mt-4 h-24 w-full" />
      ) : (
        <>
          {current && current.stations.length === 0 && (
            <div className="mt-3">
              <Alert tone="info">Saat ini semua stasiun aktif ({current.active_stations}) disinkronkan. Pilih stasiun untuk membatasinya.</Alert>
            </div>
          )}

          <div className="mt-4 flex flex-wrap items-end justify-between gap-3">
            <TextField
              label="Cari stasiun"
              type="search"
              placeholder="mis. Kranji atau KRI"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="w-full sm:w-72"
            />
            <p className="text-sm text-slate-600" aria-live="polite">
              <span className="font-semibold text-ink">{selected.length}</span> stasiun dipilih
            </p>
          </div>

          {selected.length > 0 && (
            <ul className="mt-3 flex flex-wrap gap-1.5" aria-label="Stasiun terpilih">
              {selected.map((code) => {
                const name = stations.data?.find((s) => s.code === code)?.name;
                return (
                  <li key={code}>
                    <button
                      type="button"
                      onClick={() => toggle(code)}
                      title="Klik untuk menghapus"
                      className="inline-flex items-center gap-1 rounded-full bg-brand-50 px-2.5 py-1 text-xs font-semibold text-brand-700 transition-colors hover:bg-brand-100"
                    >
                      {name ? `${name} (${code})` : code}
                      <span aria-hidden="true">×</span>
                    </button>
                  </li>
                );
              })}
            </ul>
          )}

          <div className="mt-3 max-h-64 overflow-y-auto rounded-xl border border-line">
            {stations.loading && !stations.data ? (
              <div className="space-y-2 p-3">
                {Array.from({ length: 5 }).map((_, i) => (
                  <Skeleton key={i} className="h-6 w-full" />
                ))}
              </div>
            ) : stations.error ? (
              <p className="p-4 text-sm text-red-700">Daftar stasiun tidak dapat dimuat.</p>
            ) : visible.length === 0 ? (
              <p className="p-4 text-sm text-muted">Tidak ada stasiun yang cocok.</p>
            ) : (
              <ul className="divide-y divide-line">
                {visible.map((s) => (
                  <li key={s.code}>
                    <label className="flex cursor-pointer items-center gap-3 px-3 py-2 text-sm hover:bg-slate-50">
                      <input
                        type="checkbox"
                        checked={selected.includes(s.code)}
                        onChange={() => toggle(s.code)}
                        className="h-4 w-4 rounded accent-brand-600"
                      />
                      <span className="min-w-0 flex-1 truncate text-ink">{s.name}</span>
                      <span className="tabular text-xs font-semibold text-muted">{s.code}</span>
                    </label>
                  </li>
                ))}
              </ul>
            )}
          </div>

          {error && (
            <div className="mt-3">
              <Alert>{error}</Alert>
            </div>
          )}

          <div className="mt-4 flex flex-wrap items-center gap-2">
            <Button
              onClick={() => run(() => saveSyncStations(selected), "Stasiun sinkronisasi disimpan. Berlaku pada sync berikutnya.")}
              loading={saving}
              disabled={!dirty || selected.length === 0}
            >
              Simpan
            </Button>
            {dirty && (
              <Button variant="ghost" onClick={() => setDraft(null)} disabled={saving}>
                Batalkan perubahan
              </Button>
            )}
            {current && !current.is_default && (
              <Button variant="ghost" onClick={() => run(() => resetSyncStations(), "Dikembalikan ke default (.env).")} disabled={saving}>
                Kembalikan ke default
              </Button>
            )}
          </div>

          {current && (
            <p className="mt-3 text-xs text-muted">
              Default (.env):{" "}
              {current.default_stations.length > 0 ? current.default_stations.join(", ") : "semua stasiun aktif"}
              {current.updated_at && ` · diubah ${formatDateTimeLong(current.updated_at)}${current.updated_by ? ` oleh ${current.updated_by}` : ""}`}
            </p>
          )}
        </>
      )}
    </Card>
  );
}
