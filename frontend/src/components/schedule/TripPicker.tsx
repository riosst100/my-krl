"use client";

import { usePathname, useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { getAvailableDates, getStationDestinations } from "@/lib/api/schedules";
import { useApi } from "@/lib/hooks/useApi";

interface Props {
  stationCode: string;
  stationName: string;
  date: string;
  /** Selected destination station code, if any. */
  to?: string;
  /** Extra query params to keep (e.g. station on /schedule). */
  keep?: Record<string, string>;
  showDate?: boolean;
}

const controlClass =
  "h-11 w-full rounded-lg border border-line bg-white px-3 text-ink shadow-sm focus:border-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-600/20 disabled:bg-slate-50";

/**
 * "Ke stasiun" (destination station) + date selection for a station's
 * timetable. Both are kept in the URL (?to=SUD&date=...), so results can be shared.
 */
export function TripPicker({ stationCode, stationName, date, to = "", keep = {}, showDate = true }: Props) {
  const router = useRouter();
  const pathname = usePathname();
  const [range, setRange] = useState<{ min?: string; max?: string }>({});
  const destinations = useApi(`destinations|${stationCode}|${date}`, (signal) => getStationDestinations(stationCode, date, signal));

  useEffect(() => {
    if (!showDate) return;
    getAvailableDates()
      .then(({ dates }) => setRange({ min: dates[0], max: dates[dates.length - 1] }))
      .catch(() => setRange({}));
  }, [showDate]);

  const navigate = (next: { date?: string; to?: string }) => {
    const params = new URLSearchParams(keep);
    const nextDate = next.date ?? date;
    const nextTo = next.to ?? to;
    params.set("date", nextDate);
    if (nextTo) params.set("to", nextTo);
    router.replace(`${pathname}?${params}`, { scroll: false });
  };

  const options = destinations.data ?? [];
  const noStopData = !destinations.loading && !destinations.error && options.length === 0;

  return (
    <div className={`grid gap-3 ${showDate ? "sm:grid-cols-[minmax(0,1fr)_180px]" : ""}`}>
      <div>
        <label htmlFor="trip-to" className="mb-1.5 block text-sm font-medium text-ink">
          Ke stasiun
        </label>
        <select
          id="trip-to"
          className={controlClass}
          value={to}
          disabled={destinations.loading || noStopData}
          onChange={(e) => navigate({ to: e.target.value })}
          aria-describedby="trip-to-hint"
        >
          <option value="">{destinations.loading ? "Memuat stasiun…" : "Semua tujuan"}</option>
          {options.map((s) => (
            <option key={s.code} value={s.code}>
              {s.name} ({s.code}) · {s.trains} kereta
            </option>
          ))}
        </select>
        <p id="trip-to-hint" className="mt-1.5 text-xs text-muted">
          {destinations.error
            ? "Daftar stasiun tujuan tidak dapat dimuat."
            : noStopData
              ? "Data pemberhentian kereta belum tersedia untuk tanggal ini."
              : `Hanya stasiun yang dilewati kereta setelah ${stationName}.`}
        </p>
      </div>

      {showDate && (
        <div>
          <label htmlFor="trip-date" className="mb-1.5 block text-sm font-medium text-ink">
            Tanggal
          </label>
          <input
            id="trip-date"
            type="date"
            className={controlClass}
            value={date}
            min={range.min}
            max={range.max}
            onChange={(e) => e.target.value && navigate({ date: e.target.value })}
          />
        </div>
      )}
    </div>
  );
}
