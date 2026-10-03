"use client";

import { useEffect, useMemo, useState } from "react";
import { Card, cx, EmptyState, ErrorState, LineDot, Skeleton } from "@/components/ui";
import { errorMessage } from "@/lib/api/client";
import { getStationSchedules } from "@/lib/api/schedules";
import type { Schedule } from "@/lib/api/types";
import { formatDateLong, formatDateTime, lineLabel, minutesBetween, nowTimeInJakarta, todayInJakarta } from "@/lib/format";
import { useApi } from "@/lib/hooks/useApi";

interface Props {
  stationCode: string;
  date: string;
  /** Destination station code: only trains that stop there (after this station). */
  to?: string;
}

/**
 * Departure board for one station and one date. The whole day is loaded once;
 * destination and "hide departed" filters are applied locally for speed.
 */
export function ScheduleBoard({ stationCode, date, to }: Props) {
  const { data, error, loading, reload } = useApi(`${stationCode}|${date}|${to ?? ""}`, (signal) =>
    getStationSchedules(stationCode, { date, to }, signal),
  );

  const [destination, setDestination] = useState<string>("");
  const [hideDeparted, setHideDeparted] = useState(true);
  const [clock, setClock] = useState<{ today: string; now: string } | null>(null);

  // Live clock (WIB) for "next train" highlighting; client-only to avoid hydration mismatch.
  useEffect(() => {
    const tick = () => setClock({ today: todayInJakarta(), now: nowTimeInJakarta() });
    tick();
    const id = setInterval(tick, 30_000);
    return () => clearInterval(id);
  }, []);

  const isToday = clock?.today === date;

  const rows = useMemo(() => {
    let list = data?.data ?? [];
    if (destination) list = list.filter((s) => s.destination === destination);
    if (isToday && hideDeparted && clock) list = list.filter((s) => s.departure_time >= clock.now);
    return list;
  }, [data, destination, isToday, hideDeparted, clock]);

  const nextId = isToday && clock ? rows.find((s) => s.departure_time >= clock.now)?.id : undefined;

  if (loading && !data) return <BoardSkeleton />;

  if (error) {
    return (
      <Card>
        <ErrorState message={errorMessage(error)} onRetry={reload} />
      </Card>
    );
  }

  if (!data) return null;
  const { meta } = data;
  const trip = meta.to;

  return (
    <Card className="overflow-hidden">
      <div className="border-b border-line px-4 py-4 sm:px-6">
        <div className="flex flex-wrap items-baseline justify-between gap-2">
          <h2 className="text-lg font-bold text-ink">
            {trip ? (
              <>
                {meta.station.name} → {trip.name}
              </>
            ) : (
              "Keberangkatan"
            )}{" "}
            · <span className="tabular">{formatDateLong(date)}</span>
          </h2>
          <p className="text-xs text-muted">Diperbarui {formatDateTime(meta.last_synced_at)}</p>
        </div>

        {meta.total_for_date > 0 && (
          <div className="mt-4 flex flex-wrap items-center gap-2" role="group" aria-label="Filter arah tujuan">
            {!trip && (
              <Chip active={destination === ""} onClick={() => setDestination("")}>
                Semua arah
              </Chip>
            )}
            {!trip && meta.destinations.map((d) => (
              <Chip key={d} active={destination === d} onClick={() => setDestination(d)}>
                → {d}
              </Chip>
            ))}
            {isToday && (
              <label className="ml-auto flex cursor-pointer items-center gap-2 text-sm text-slate-600">
                <input
                  type="checkbox"
                  checked={hideDeparted}
                  onChange={(e) => setHideDeparted(e.target.checked)}
                  className="h-4 w-4 accent-brand-600"
                />
                Sembunyikan yang sudah berangkat
              </label>
            )}
          </div>
        )}
      </div>

      {meta.total_for_date === 0 ? (
        <EmptyState
          title="Belum ada jadwal untuk tanggal ini"
          description="Jadwal mungkin belum disinkronkan. Coba pilih tanggal lain."
        />
      ) : trip && (data.data.length === 0) ? (
        <EmptyState
          title={`Tidak ada kereta langsung ke ${trip.name}`}
          description={`Tidak ada kereta dari ${meta.station.name} yang berhenti di ${trip.name} pada tanggal ini. Mungkin perlu transit di stasiun lain.`}
        />
      ) : rows.length === 0 ? (
        <EmptyState
          title="Tidak ada kereta yang cocok"
          description={isToday && hideDeparted ? "Semua kereta hari ini sudah berangkat. Tampilkan semua jadwal untuk melihatnya." : "Ubah filter arah tujuan."}
        />
      ) : (
        <>
          {/* Desktop table */}
          <table className="hidden w-full text-left text-sm md:table">
            <caption className="sr-only">Jadwal keberangkatan kereta dari stasiun {meta.station.name}</caption>
            <thead className="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-muted">
              <tr>
                <th scope="col" className="px-6 py-3">Berangkat</th>
                <th scope="col" className="px-4 py-3">Kereta</th>
                {trip ? (
                  <>
                    <th scope="col" className="px-4 py-3">Tiba di {trip.name}</th>
                    <th scope="col" className="px-4 py-3">Tujuan akhir kereta</th>
                  </>
                ) : (
                  <>
                    <th scope="col" className="px-4 py-3">Tujuan</th>
                    <th scope="col" className="px-4 py-3">Tiba di tujuan</th>
                  </>
                )}
                <th scope="col" className="px-6 py-3">Line</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-line">
              {rows.map((s) => (
                <tr key={s.id} className={cx(s.id === nextId && "bg-brand-50/60")}>
                  <td className="px-6 py-3">
                    <span className="tabular text-base font-bold text-ink">{s.departure_time}</span>
                    {s.id === nextId && <NextBadge />}
                  </td>
                  <td className="tabular px-4 py-3 font-semibold">KA {s.train_number}</td>
                  {trip ? (
                    <>
                      <td className="tabular px-4 py-3">
                        <span className="text-base font-bold text-ink">{s.to_station_arrival_time}</span>
                        <TripInfo schedule={s} />
                      </td>
                      <td className="px-4 py-3 text-slate-600">
                        {s.destination}
                        {s.route_name && <span className="block text-xs text-muted">Rute {s.route_name}</span>}
                      </td>
                    </>
                  ) : (
                    <>
                      <td className="px-4 py-3">
                        <span className="font-medium">{s.destination}</span>
                        {s.route_name && <span className="block text-xs text-muted">Rute {s.route_name}</span>}
                      </td>
                      <td className="tabular px-4 py-3 text-slate-600">
                        <ArrivalText schedule={s} />
                      </td>
                    </>
                  )}
                  <td className="px-6 py-3">
                    <span className="inline-flex items-center gap-2 text-slate-600">
                      <LineDot color={s.color ?? s.line?.color} />
                      {lineLabel(s.line?.name)}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>

          {/* Mobile list */}
          <ul className="divide-y divide-line md:hidden">
            {rows.map((s) => (
              <li key={s.id} className={cx("flex items-center gap-4 px-4 py-3", s.id === nextId && "bg-brand-50/60")}>
                <div className="w-16 shrink-0">
                  <p className="tabular text-lg font-bold leading-tight text-ink">{s.departure_time}</p>
                  {s.id === nextId && <NextBadge />}
                </div>
                <div className="min-w-0 flex-1">
                  <p className="truncate font-semibold text-ink">
                    {trip ? `KA ${s.train_number} · arah ${s.destination}` : `→ ${s.destination}`}
                  </p>
                  {s.route_name && <p className="truncate text-xs text-muted">Rute {s.route_name}</p>}
                  <p className="mt-0.5 flex items-center gap-1.5 text-xs text-muted">
                    <LineDot color={s.color ?? s.line?.color} />
                    <span className="tabular">KA {s.train_number}</span>
                    <span aria-hidden="true">·</span>
                    <span className="truncate">{lineLabel(s.line?.name)}</span>
                  </p>
                </div>
                <div className="tabular shrink-0 text-right text-xs text-muted">
                  {trip ? (
                    <>
                      <span className="block">tiba {trip.code}</span>
                      <span className="text-base font-bold text-ink">{s.to_station_arrival_time}</span>
                      <TripInfo schedule={s} compact />
                    </>
                  ) : (
                    <ArrivalText schedule={s} compact />
                  )}
                </div>
              </li>
            ))}
          </ul>

          <p className="border-t border-line px-4 py-3 text-xs text-muted sm:px-6">
            {trip
              ? `Menampilkan ${rows.length} kereta yang berhenti di ${trip.name} (dari ${meta.total_for_date} kereta di ${meta.station.name}).`
              : `Menampilkan ${rows.length} dari ${meta.total_for_date} kereta.`}{" "}
            Waktu dalam WIB.
          </p>
        </>
      )}
    </Card>
  );
}

function ArrivalText({ schedule, compact }: { schedule: Schedule; compact?: boolean }) {
  if (!schedule.destination_arrival_time) return <span>—</span>;
  const duration = minutesBetween(schedule.departure_time, schedule.destination_arrival_time);
  return (
    <span>
      {compact && <span className="block">tiba</span>}
      {schedule.destination_arrival_time}
      {duration > 0 && <span className="text-slate-400"> {compact ? <br /> : "· "}{duration} mnt</span>}
    </span>
  );
}

/** Travel time and number of stops to the chosen destination station. */
function TripInfo({ schedule, compact }: { schedule: Schedule; compact?: boolean }) {
  if (!schedule.to_station_arrival_time) return null;
  const duration = minutesBetween(schedule.departure_time, schedule.to_station_arrival_time);
  const stops = schedule.stops_to_station ?? 0;
  return (
    <span className={cx("text-xs text-slate-400", compact ? "block" : "ml-2")}>
      {duration} mnt · {stops} stasiun
    </span>
  );
}

function NextBadge() {
  return (
    <span className="ml-0 mt-0.5 block w-fit rounded bg-brand-600 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white md:ml-2 md:mt-0 md:inline">
      Berikutnya
    </span>
  );
}

function Chip({ active, onClick, children }: { active: boolean; onClick: () => void; children: React.ReactNode }) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      className={cx(
        "rounded-full border px-3 py-1.5 text-sm font-medium transition-colors",
        active ? "border-brand-600 bg-brand-600 text-white" : "border-line bg-white text-slate-700 hover:border-slate-300",
      )}
    >
      {children}
    </button>
  );
}

function BoardSkeleton() {
  return (
    <div className="rounded-2xl border border-line bg-surface p-4 shadow-sm sm:p-6" aria-busy="true">
      <span className="sr-only">Memuat jadwal…</span>
      <Skeleton className="h-6 w-64" />
      <div className="mt-4 flex gap-2">
        <Skeleton className="h-8 w-24 rounded-full" />
        <Skeleton className="h-8 w-28 rounded-full" />
        <Skeleton className="h-8 w-28 rounded-full" />
      </div>
      <div className="mt-6 space-y-4">
        {Array.from({ length: 8 }).map((_, i) => (
          <div key={i} className="flex items-center gap-4">
            <Skeleton className="h-6 w-14" />
            <Skeleton className="h-5 flex-1" />
            <Skeleton className="h-5 w-20" />
          </div>
        ))}
      </div>
    </div>
  );
}
