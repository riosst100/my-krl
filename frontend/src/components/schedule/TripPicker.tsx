"use client";

import { usePathname, useRouter } from "next/navigation";
import { getStationDestinations } from "@/lib/api/schedules";
import { useApi } from "@/lib/hooks/useApi";
import { controlClass as baseControlClass, cx, selectClass } from "@/components/ui";

interface Props {
  stationCode: string;
  stationName: string;
  /** Selected destination station code, if any. */
  to?: string;
  /** Extra query params to keep (e.g. station on /schedule). */
  keep?: Record<string, string>;
}

const controlClass = cx(baseControlClass, "h-11 border-line");
const labelClass = "mb-1.5 block text-[13px] font-semibold text-slate-700";

/**
 * "Ke stasiun" (destination station) selection for a station's
 * timetable, kept in the URL (?to=SUD) so results can be shared.
 */
export function TripPicker({ stationCode, stationName, to = "", keep = {} }: Props) {
  const router = useRouter();
  const pathname = usePathname();
  const destinations = useApi(`destinations|${stationCode}`, (signal) => getStationDestinations(stationCode, signal));

  const navigate = (next: { to: string }) => {
    const params = new URLSearchParams(keep);
    if (next.to) params.set("to", next.to);
    router.replace(`${pathname}?${params}`, { scroll: false });
  };

  const options = destinations.data ?? [];
  const noStopData = !destinations.loading && !destinations.error && options.length === 0;

  return (
    <div className="grid gap-3">
      <div>
        <label htmlFor="trip-to" className={labelClass}>
          Ke stasiun
        </label>
        <select
          id="trip-to"
          className={cx(controlClass, selectClass)}
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
              ? "Data pemberhentian kereta belum tersedia."
              : `Hanya stasiun yang dilewati kereta setelah ${stationName}.`}
        </p>
      </div>

    </div>
  );
}
