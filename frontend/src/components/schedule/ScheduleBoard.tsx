"use client";

import { useMemo, useState } from "react";
import { DepartureRow } from "@/components/favorites/DepartureRow";
import { TripDetailDialog } from "@/components/favorites/TripDetailDialog";
import { Card, cx, EmptyState, ErrorState, Skeleton } from "@/components/ui";
import { errorMessage } from "@/lib/api/client";
import { getStationSchedules } from "@/lib/api/schedules";
import type { Schedule } from "@/lib/api/types";
import {
  formatCountdown,
  formatDateLong,
  formatDateTime,
  secondsUntil,
} from "@/lib/format";
import { useApi } from "@/lib/hooks/useApi";
import { useJakartaClock } from "@/lib/hooks/useJakartaClock";

interface Props {
  stationCode: string;
  /** Destination station code: only trains that stop there (after this station). */
  to?: string;
}

/**
 * Departure board for one station and one date. The whole day is loaded once;
 * destination and "hide departed" filters are applied locally for speed.
 */
export function ScheduleBoard({ stationCode, to }: Props) {
  // The API always serves the latest synced timetable (meta.date).
  const { data, error, loading, reload } = useApi(
    `${stationCode}|${to ?? ""}`,
    (signal) => getStationSchedules(stationCode, { to }, signal),
  );
  const date = data?.meta.date;

  const [destination, setDestination] = useState<string>("");
  const [hideDeparted, setHideDeparted] = useState(true);
  const [selected, setSelected] = useState<Schedule | null>(null);
  // Live WIB clock, updated every minute, for "next train" highlighting and its countdown.
  const live = useJakartaClock();
  const clock = useMemo(() => {
    if (!live) return null;
    const minutes = Math.floor(live.seconds / 60);
    const now = `${String(Math.floor(minutes / 60)).padStart(2, "0")}:${String(minutes % 60).padStart(2, "0")}`;
    return { today: live.date, now };
  }, [live]);

  const isToday = !!date && clock?.today === date;

  const rows = useMemo(() => {
    let list = data?.data ?? [];
    if (destination) list = list.filter((s) => s.destination === destination);
    if (isToday && hideDeparted && clock)
      list = list.filter((s) => s.departure_time >= clock.now);
    return list;
  }, [data, destination, isToday, hideDeparted, clock]);

  const next =
    isToday && clock
      ? rows.find((s) => s.departure_time >= clock.now)
      : undefined;
  const nextId = next?.id;
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
        <div className="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between sm:gap-3">
          <h2 className="text-base font-bold leading-snug text-ink sm:text-lg">
            {trip ? (
              <>
                {meta.station.name} → {trip.name}
              </>
            ) : (
              "Keberangkatan"
            )}{" "}
            <span className="font-medium text-muted sm:font-bold sm:text-ink">
              · <span className="tabular">{formatDateLong(meta.date)}</span>
            </span>
          </h2>
          <p className="shrink-0 text-xs text-muted">
            Diperbarui {formatDateTime(meta.last_synced_at)}
          </p>
        </div>

        {meta.total_for_date > 0 && (
          <div className="mt-3 flex flex-col gap-3 sm:mt-4 sm:flex-row sm:flex-wrap sm:items-center">
            {!trip && (
              // One swipeable row on phones; wraps on larger screens.
              <div
                role="group"
                aria-label="Filter arah tujuan"
                className="no-scrollbar -mx-4 flex gap-2 overflow-x-auto px-4 sm:mx-0 sm:flex-1 sm:flex-wrap sm:overflow-visible sm:px-0"
              >
                <Chip
                  active={destination === ""}
                  onClick={() => setDestination("")}
                >
                  Semua arah
                </Chip>
                {meta.destinations.map((d) => (
                  <Chip
                    key={d}
                    active={destination === d}
                    onClick={() => setDestination(d)}
                  >
                    → {d}
                  </Chip>
                ))}
              </div>
            )}
            {isToday && (
              <label className="flex w-fit cursor-pointer items-center gap-2 text-[13px] text-slate-600 sm:ml-auto">
                <input
                  type="checkbox"
                  checked={hideDeparted}
                  onChange={(e) => setHideDeparted(e.target.checked)}
                  className="h-4 w-4 rounded accent-brand-600"
                />
                Sembunyikan yang sudah berangkat
              </label>
            )}
          </div>
        )}
      </div>

      {meta.total_for_date === 0 ? (
        <EmptyState
          title="Belum ada jadwal"
          description="Jadwal belum disinkronkan. Coba beberapa saat lagi."
        />
      ) : trip && data.data.length === 0 ? (
        <EmptyState
          title={`Tidak ada kereta langsung ke ${trip.name}`}
          description={`Tidak ada kereta dari ${meta.station.name} yang berhenti di ${trip.name} saat ini. Mungkin perlu transit di stasiun lain.`}
        />
      ) : rows.length === 0 ? (
        <EmptyState
          title="Tidak ada kereta yang cocok"
          description={
            isToday && hideDeparted
              ? "Jadwal KRL selesai sampai jam 12 malam. Kereta mulai berangkat lagi pukul 04:00 WIB. Tampilkan semua jadwal untuk melihat jadwal hari ini."
              : "Ubah filter arah tujuan."
          }
        />
      ) : (
        <>
          {/* The same train cards as the homepage's favourite routes. */}
          <ul className="p-2 sm:p-3">
            {rows.map((s) => (
              <DepartureRow
                key={s.id}
                schedule={s}
                fromName={meta.station.name}
                toName={trip?.name}
                first={s.id === nextId}
                countdown={
                  isToday && live
                    ? formatCountdown(
                        secondsUntil(s.service_date, s.departure_time, live),
                      )
                    : undefined
                }
                onOpen={() => setSelected(s)}
              />
            ))}
          </ul>
          <TripDetailDialog
            schedule={selected}
            from={meta.station}
            to={trip}
            onClose={() => setSelected(null)}
          />

          <p className="border-t border-line px-4 py-3 text-xs text-muted sm:px-6">
            {trip
              ? `Menampilkan ${rows.length} kereta yang berhenti di ${trip.name} (dari ${meta.total_for_date} kereta di ${meta.station.name}).`
              : `Menampilkan ${rows.length} dari ${meta.total_for_date} kereta.`}{" "}
            Jadwal terbaru dari sinkronisasi terakhir · waktu dalam WIB.
          </p>
        </>
      )}
    </Card>
  );
}

function Chip({
  active,
  onClick,
  children,
}: {
  active: boolean;
  onClick: () => void;
  children: React.ReactNode;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      className={cx(
        "shrink-0 whitespace-nowrap rounded-full border px-3 py-1.5 text-[13px] font-medium transition-colors",
        active
          ? "border-brand-600 bg-brand-600 text-white shadow-sm shadow-brand-600/20"
          : "border-line bg-white text-slate-700 hover:border-slate-300",
      )}
    >
      {children}
    </button>
  );
}

function BoardSkeleton() {
  return (
    <div
      className="rounded-2xl border border-line/80 bg-surface p-4 shadow-card sm:p-6"
      aria-busy="true"
    >
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
