import { LineDot } from "@/components/ui";
import type { Schedule } from "@/lib/api/types";
import { lineLabel } from "@/lib/format";

/**
 * One train of a route (departure station -> chosen destination station): the
 * departure and the arrival as two separate boxes, the final destination and a
 * button for the trip detail.
 */
export function DepartureRow({
  schedule: s,
  fromName,
  toName,
  first,
  countdown,
  onOpen,
}: {
  schedule: Schedule;
  fromName: string;
  toName: string;
  first: boolean;
  /** Shown as a badge when given (e.g. "5 menit lagi"). */
  countdown?: string;
  onOpen: () => void;
}) {
  const arrival = s.to_station_arrival_time;

  return (
    <li
      // Rows share the route card: the next train is tinted, the others are separated by a hairline.
      className={`p-3 sm:p-4 ${first ? "rounded-xl bg-brand-50/70" : "border-t border-line"}`}
    >
      <div className="flex items-center justify-between gap-2">
        <p className="flex min-w-0 items-center gap-1.5 text-[11px] text-muted sm:text-xs">
          <LineDot color={s.color ?? s.line?.color} />
          <span className="tabular font-semibold text-slate-700">
            KA {s.train_number}
          </span>
          <span aria-hidden="true">·</span>
          <span className="truncate">{lineLabel(s.line?.name)}</span>
        </p>
        {countdown && (
          <span
            className={`shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold sm:text-xs ${first ? "bg-brand-100 text-brand-700" : "bg-slate-100 text-slate-600"}`}
          >
            {countdown}
          </span>
        )}
      </div>

      {/* Departure and arrival: two separate boxes. */}
      <div
        className={`mt-2.5 grid gap-2 ${arrival ? "grid-cols-2" : "grid-cols-1"}`}
      >
        <TimeBox
          label="Berangkat"
          tone="depart"
          time={s.departure_time}
          station={fromName}
        />
        {arrival && (
          <TimeBox label="Tiba" tone="arrive" time={arrival} station={toName} />
        )}
      </div>

      <div className="mt-2.5 flex flex-wrap items-center justify-between gap-x-3 gap-y-2 border-t border-slate-200/80 pt-2.5">
        <p className="min-w-0 text-[11px] leading-snug text-slate-600 sm:text-xs">
          Tujuan akhir{" "}
          <span className="font-semibold text-ink">{s.destination}</span>
        </p>
        <button
          type="button"
          onClick={onOpen}
          aria-label={`Lihat perjalanan KA ${s.train_number}`}
          className="shrink-0 rounded-full border border-brand-600/40 bg-white px-3 py-1 text-xs font-semibold text-brand-600 transition-colors hover:bg-brand-50"
        >
          Lihat perjalanan →
        </button>
      </div>
    </li>
  );
}

function TimeBox({
  label,
  tone,
  time,
  station,
}: {
  label: string;
  tone: "depart" | "arrive";
  time: string;
  station: string;
}) {
  const arrive = tone === "arrive";

  return (
    // A thin border, white fill and a tinted label strip keep the two times apart from the surrounding text.
    <div
      className={`min-w-0 overflow-hidden rounded-lg border bg-white ${arrive ? "border-brand-600/30" : "border-line"}`}
    >
      <p
        className={`px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wider ${arrive ? "bg-brand-50 text-brand-700" : "bg-slate-100 text-slate-600"}`}
      >
        {label}
      </p>
      <div className="px-2.5 py-2">
        <p className="tabular text-xl font-extrabold leading-none text-ink sm:text-2xl">
          {time} <span className="text-[10px] font-medium text-muted">WIB</span>
        </p>
        <p className="mt-1 truncate text-xs leading-tight text-slate-600">
          {station}
        </p>
      </div>
    </div>
  );
}
