"use client";

import { ApiSyncPanel } from "@/components/admin/ApiSyncPanel";
import {
  getSchedulesApiSetting,
  getTrainStopsApiSetting,
  resetTrainStopsApiUrl,
  saveTrainStopsApiUrl,
  testTrainStopsApiUrl,
  resetSchedulesApiUrl,
  saveSchedulesApiUrl,
  testSchedulesApiUrl,
  triggerSync,
  type SyncLogsMeta,
} from "@/lib/api/admin";
import { formatNumber, lineLabel } from "@/lib/format";

/** Admin → Sinkronisasi: Schedules API URL + daily schedule sync. */
export function ScheduleSyncPanel({ meta, onChanged }: { meta: SyncLogsMeta | undefined; onChanged: () => void }) {
  return (
    <>
    <ApiSyncPanel
      title="Sumber data jadwal"
      urlLabel="Schedules API URL"
      urlHint="Nilai “stationid” (atau {station}) diganti otomatis dengan kode tiap stasiun yang disinkronkan. Data API tidak bertanggal, jadi disimpan untuk hari ini. Kosongkan untuk memakai klien KCI (mock/HTTP)."
      emptyUrlText="Tidak memakai URL — jadwal diambil dari klien KCI (mock/HTTP)"
      settingKey="schedules-api"
      getSetting={getSchedulesApiSetting}
      saveUrl={saveSchedulesApiUrl}
      resetUrl={resetSchedulesApiUrl}
      testUrl={(url) => testSchedulesApiUrl(url)}
      renderPreview={(p) => (
        <>
          <p className="font-semibold">
            URL dapat dibaca: {formatNumber(p.count)} kereta di stasiun {p.station} ({p.first}–{p.last}).
          </p>
          <p className="mt-1">Line: {p.lines.map(lineLabel).join(", ")}</p>
          <ul className="mt-2 space-y-0.5 font-mono text-xs">
            {p.sample.map((s) => (
              <li key={s.train_number}>
                {s.departure_time} · KA {s.train_number} → {s.destination}
                {s.destination_arrival_time ? ` (tiba ${s.destination_arrival_time})` : ""}
              </li>
            ))}
          </ul>
        </>
      )}
      sync={{
        last: meta?.last_schedule_sync,
        lastSuccess: meta?.last_successful_schedule_sync,
        inProgress: meta?.in_progress ?? false,
        next: meta?.next_schedule_sync,
        scheduleText: "Sync otomatis setiap hari",
        summary: (log) => {
          const stations = Array.isArray(log.meta?.stations) ? log.meta.stations.join(", ") : "semua stasiun aktif";
          const stops = log.meta?.train_stops;
          return (
            `${formatNumber(log.records_processed)} jadwal · ${formatNumber(log.stations_processed)} stasiun (${stations}) · ${log.meta?.days ?? 1} hari` +
            (stops ? ` · pemberhentian ${formatNumber(stops.fetched + stops.skipped)}/${formatNumber(stops.trains)} kereta` : "")
          );
        },
        syncLabel: "Sync KCI Data Now",
        startedMessage: "Sinkronisasi dimulai. Status akan diperbarui otomatis.",
        conflictMessage: "Sinkronisasi lain sedang berjalan.",
        triggerSync,
        onChanged,
      }}
    />
    <ApiSyncPanel
      title="Pemberhentian kereta"
      urlLabel="Train Stops API URL"
      urlHint="Nilai “trainid” (atau {train}) diganti otomatis dengan nomor tiap kereta. Dipakai untuk pilihan “Ke stasiun”. Kosongkan untuk menonaktifkan."
      emptyUrlText="Tidak aktif — pencarian “Ke stasiun” tidak tersedia"
      settingKey="train-stops-api"
      getSetting={getTrainStopsApiSetting}
      saveUrl={saveTrainStopsApiUrl}
      resetUrl={resetTrainStopsApiUrl}
      testUrl={(url) => testTrainStopsApiUrl(url)}
      note="Disinkronkan bersama jadwal (tombol di atas); kereta yang pemberhentiannya sudah tersimpan hari itu dilewati."
      renderPreview={(p) => (
        <>
          <p className="font-semibold">
            URL dapat dibaca: KA {p.train} berhenti di {p.count} stasiun.
          </p>
          <p className="mt-1 font-mono text-xs">{p.stops.map((s) => `${s.station_code} ${s.time}`).join(" → ")}</p>
        </>
      )}
    />
    </>
  );
}
