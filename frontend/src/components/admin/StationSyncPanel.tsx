"use client";

import { ApiSyncPanel } from "@/components/admin/ApiSyncPanel";
import {
  getStationsApiSetting,
  resetStationsApiUrl,
  saveStationsApiUrl,
  testStationsApiUrl,
  triggerStationSync,
  type AdminStationsMeta,
} from "@/lib/api/admin";
import { formatNumber } from "@/lib/format";

/** Admin → Stasiun: Stations API URL + monthly station sync. */
export function StationSyncPanel({ meta, onChanged }: { meta: AdminStationsMeta | undefined; onChanged: () => void }) {
  const missing = meta?.last_station_sync?.meta?.missing_from_kci ?? [];

  return (
    <>
      <ApiSyncPanel
        title="Sumber data stasiun"
        urlLabel="Stations API URL"
        urlHint="Kosongkan untuk mengambil daftar stasiun dari klien KCI (mock/HTTP). Format JSON: array, atau array di dalam “data” / “stations”."
        emptyUrlText="Tidak memakai URL — daftar stasiun diambil dari klien KCI (mock/HTTP)"
        settingKey="stations-api"
        getSetting={getStationsApiSetting}
        saveUrl={saveStationsApiUrl}
        resetUrl={resetStationsApiUrl}
        testUrl={testStationsApiUrl}
        renderPreview={(p) => (
          <>
            <p className="font-semibold">URL dapat dibaca: {formatNumber(p.count)} stasiun ditemukan.</p>
            <p className="mt-1">Contoh: {p.sample.map((s) => `${s.name} (${s.code})`).join(", ")}</p>
          </>
        )}
        sync={{
          last: meta?.last_station_sync,
          lastSuccess: meta?.last_successful_station_sync,
          inProgress: meta?.station_sync_in_progress ?? false,
          next: meta?.next_station_sync,
          scheduleText: "Sync otomatis setiap bulan",
          summary: (log) => `${formatNumber(log.records_processed)} stasiun · baru ${log.meta?.created ?? 0} · berubah ${log.meta?.updated ?? 0}`,
          syncLabel: "Sync Stasiun Sekarang",
          startedMessage: "Sinkronisasi data stasiun dimulai.",
          conflictMessage: "Sinkronisasi stasiun sedang berjalan.",
          triggerSync: triggerStationSync,
          onChanged,
        }}
      />
      {missing.length > 0 && (
        <p className="-mt-4 mb-6 text-sm text-amber-700">Tidak lagi ada di sumber: {missing.join(", ")} (tidak dihapus otomatis)</p>
      )}
    </>
  );
}
