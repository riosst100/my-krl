"use client";

import { useEffect, useState } from "react";
import { TripDetailDialog } from "@/components/favorites/TripDetailDialog";
import { Card, LineDot } from "@/components/ui";
import { formatCountdown, lineLabel, secondsUntil } from "@/lib/format";
import { useBoardedTrain } from "@/lib/hooks/useBoardedTrain";
import { useJakartaClock } from "@/lib/hooks/useJakartaClock";

/**
 * "Perjalanan Anda": the train the visitor marked "Saya sudah naik ini", with
 * where it is now (waiting, on its way, arrived) and a progress bar.
 * Only rendered while such a train is set.
 */
export function BoardedTripSection() {
  const { boarded, clear } = useBoardedTrain();
  const clock = useJakartaClock();
  const [open, setOpen] = useState(false);

  // A trip from a previous service day is stale: drop it.
  const stale =
    !!boarded && !!clock && boarded.schedule.service_date < clock.date;
  useEffect(() => {
    if (stale) clear();
  }, [stale, clear]);

  if (!boarded || stale) return null;
  const { schedule: s, from, to } = boarded;
  const toName = to?.name ?? s.destination;
  const arrival = to ? s.to_station_arrival_time : s.destination_arrival_time;

  let status = "";
  let progress = 0;
  if (clock) {
    const untilDep = secondsUntil(s.service_date, s.departure_time, clock);
    // An arrival earlier than the departure time means it is after midnight.
    const untilArr = arrival
      ? secondsUntil(s.service_date, arrival, clock) +
        (arrival < s.departure_time ? 86_400 : 0)
      : null;
    if (untilDep > 0) {
      status = `Berangkat ${formatCountdown(untilDep)}`;
    } else if (untilArr !== null && untilArr > 0) {
      status = `Dalam perjalanan · tiba ${formatCountdown(untilArr)}`;
      progress = -untilDep / (untilArr - untilDep);
    } else {
      status = untilArr === null ? "Sudah berangkat" : "Sudah tiba";
      progress = 1;
    }
  }

  return (
    <section aria-labelledby="boarded-title" className="mb-8 sm:mb-10">
      <h2
        id="boarded-title"
        className="mb-3 text-base font-bold leading-snug tracking-tight text-ink sm:mb-4 sm:text-xl"
      >
        Perjalanan Anda
      </h2>
      <Card className="overflow-hidden border-brand-600/40">
        <div className="bg-brand-600 px-4 py-3 text-white sm:px-5">
          <p className="flex items-center gap-1.5 text-xs font-medium text-white/85">
            <LineDot color={s.color ?? s.line?.color} />
            <span className="tabular font-semibold">KA {s.train_number}</span>
            <span aria-hidden="true">·</span>
            <span className="truncate">{lineLabel(s.line?.name)}</span>
          </p>
          <p role="status" className="mt-1 text-[15px] font-bold sm:text-base">
            {status || " "}
          </p>
        </div>

        <div className="px-4 py-4 sm:px-5">
          <div className="flex items-end justify-between gap-3">
            <div className="min-w-0">
              <p className="text-[11px] font-semibold uppercase tracking-wide text-muted">
                Berangkat
              </p>
              <p className="tabular text-2xl font-extrabold leading-tight text-ink">
                {s.departure_time}
              </p>
              <p className="truncate text-[13px] text-slate-600">{from.name}</p>
            </div>
            <div className="min-w-0 text-right">
              <p className="text-[11px] font-semibold uppercase tracking-wide text-muted">
                Tiba
              </p>
              <p className="tabular text-2xl font-extrabold leading-tight text-ink">
                {arrival ?? "—"}
              </p>
              <p className="truncate text-[13px] text-slate-600">{toName}</p>
            </div>
          </div>

          <div
            className="mt-3 h-1.5 bg-slate-200"
            role="progressbar"
            aria-label="Kemajuan perjalanan"
            aria-valuemin={0}
            aria-valuemax={100}
            aria-valuenow={Math.round(progress * 100)}
          >
            <div
              className="h-full bg-brand-600 transition-[width] duration-700"
              style={{ width: `${Math.min(1, Math.max(0, progress)) * 100}%` }}
            />
          </div>

          <p className="mt-3 text-[11px] text-slate-600 sm:text-xs">
            Tujuan akhir kereta{" "}
            <span className="font-semibold text-ink">{s.destination}</span>
          </p>

          <div className="mt-3 flex justify-end gap-2 border-t border-line pt-3">
            <button
              type="button"
              onClick={clear}
              className="rounded-full border border-line bg-white px-3 py-1 text-xs font-semibold text-slate-600 transition-colors hover:bg-slate-50"
            >
              Batalkan
            </button>
            <button
              type="button"
              onClick={() => setOpen(true)}
              className="rounded-full border border-brand-600/40 bg-white px-3 py-1 text-xs font-semibold text-brand-600 transition-colors hover:bg-brand-50"
            >
              Lihat perjalanan →
            </button>
          </div>
        </div>

        <TripDetailDialog
          schedule={open ? s : null}
          from={from}
          to={to}
          onClose={() => setOpen(false)}
        />
      </Card>
    </section>
  );
}
