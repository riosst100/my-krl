import { LineDot } from "@/components/ui";
import { useBoardedTrain, type StationRef } from "@/lib/hooks/useBoardedTrain";
import type { Schedule } from "@/lib/api/types";
import {
  DEPARTED_GRACE_SECONDS,
  formatCountdown,
  lineLabel,
  URGENT_SECONDS,
} from "@/lib/format";

/**
 * One train: the departure and the arrival as two separate boxes (arrival at
 * the chosen destination station, or at the train's final destination), and a
 * button for the trip detail.
 */
export function DepartureRow({
  schedule: s,
  from,
  to,
  secondsLeft,
  onOpen,
}: {
  schedule: Schedule;
  from: StationRef;
  /** Chosen destination station; without it the train's final destination is used. */
  to?: StationRef | null;
  /** Seconds until departure; shown as a countdown badge. Under 5 minutes the row turns red. */
  secondsLeft?: number;
  onOpen: () => void;
}) {
  // With a chosen destination: arrival there. Otherwise: arrival at the final destination.
  // Leaves within 5 minutes (or has just left): the whole row is red.
  const urgent =
    secondsLeft !== undefined &&
    secondsLeft <= URGENT_SECONDS &&
    secondsLeft > -DEPARTED_GRACE_SECONDS;
  const toName = to?.name;
  const arrival = toName
    ? s.to_station_arrival_time
    : s.destination_arrival_time;
  const arrivalStation = toName ?? s.destination;
  const { isBoarded, toggle } = useBoardedTrain();
  const boarded = isBoarded(s);

  return (
    <li
      // Rows share the route card: the next train is tinted, the others are separated by a hairline.
      className={`px-2 py-3 sm:p-4 ${urgent ? "rounded-none bg-brand-100/80" : "border-t border-line first:border-t-0"}`}
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
        {secondsLeft !== undefined && (
          <span
            className={`shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold sm:text-xs ${urgent ? "bg-brand-600 text-white" : "bg-slate-100 text-slate-600"}`}
          >
            {formatCountdown(secondsLeft)}
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
          station={from.name}
        />
        {arrival && (
          <TimeBox
            label="Tiba"
            tone="arrive"
            time={arrival}
            station={arrivalStation}
          />
        )}
      </div>

      <div className="mt-2.5 flex flex-wrap items-center justify-between gap-x-3 gap-y-2 border-t border-slate-200/80 pt-2.5">
        {toName && (
          <p className="min-w-0 text-[11px] leading-snug text-slate-600 sm:text-xs">
            Tujuan akhir{" "}
            <span className="font-semibold text-ink">{s.destination}</span>
          </p>
        )}
        <div className="ml-auto flex shrink-0 flex-wrap justify-end gap-2">
          <button
            type="button"
            onClick={() => toggle({ schedule: s, from, to: to ?? null })}
            aria-pressed={boarded}
            aria-label={`Saya sudah naik KA ${s.train_number}`}
            className={`rounded-full border px-3 py-1 text-xs font-semibold transition-colors ${boarded ? "border-slate-300 bg-slate-200 text-slate-600" : "border-brand-600/40 bg-white text-brand-600 hover:bg-brand-50"}`}
          >
            {boarded ? "✓ Sudah naik" : "Saya sudah naik ini"}
          </button>
          <button
            type="button"
            onClick={onOpen}
            aria-label={`Lihat perjalanan KA ${s.train_number}`}
            className="rounded-full border border-brand-600/40 bg-white px-3 py-1 text-xs font-semibold text-brand-600 transition-colors hover:bg-brand-50"
          >
            Lihat perjalanan →
          </button>
        </div>
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
      className={`min-w-0 overflow-hidden rounded-none border bg-white ${arrive ? "border-brand-600/30" : "border-line"}`}
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
