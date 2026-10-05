"use client";

import { Fragment, useEffect, useRef, useState } from "react";
import { KrlFront } from "@/components/favorites/KrlFront";
import { cx, ErrorState, Skeleton } from "@/components/ui";
import { errorMessage } from "@/lib/api/client";
import { getTrainStops } from "@/lib/api/schedules";
import type { Schedule, Station } from "@/lib/api/types";
import { lineLabel, secondsUntil } from "@/lib/format";
import { useApi } from "@/lib/hooks/useApi";
import { useBoardedTrain } from "@/lib/hooks/useBoardedTrain";
import { useJakartaClock } from "@/lib/hooks/useJakartaClock";

type StationRef = Pick<Station, "code" | "name" | "slug">;

interface Props {
  /** The train to show; null = closed. */
  schedule: Schedule | null;
  from: StationRef;
  /** Chosen destination station; null = the stops run up to the train's final destination. */
  to: StationRef | null;
  onClose: () => void;
}

/**
 * "Lihat perjalanan": the train, its times and the stations it passes from
 * the departure station up to the destination station of the route (or the
 * train's final destination).
 */
export function TripDetailDialog({ schedule, from, to, onClose }: Props) {
  const ref = useRef<HTMLDialogElement>(null);
  const open = schedule !== null;
  const { data, error, loading, reload } = useApi(
    schedule ? `trip|${schedule.service_date}|${schedule.train_number}` : null,
    (signal) => getTrainStops(schedule!.train_number, signal),
  );

  useEffect(() => {
    const dialog = ref.current;
    if (!dialog) return;
    if (open && !dialog.open) dialog.showModal();
    else if (!open && dialog.open) dialog.close();
  }, [open]);

  // Only the part of the journey the user asked for: departure station -> destination station.
  const fromIndex = data?.findIndex((s) => s.station.code === from.code) ?? -1;
  const toIndex = to
    ? (data?.findIndex((s, i) => s.station.code === to.code && i > fromIndex) ??
      -1)
    : (data?.length ?? 0) - 1;
  const stops =
    data && fromIndex >= 0 && toIndex > fromIndex
      ? data.slice(fromIndex, toIndex + 1)
      : null;
  const toName =
    to?.name ?? stops?.at(-1)?.station.name ?? schedule?.destination ?? "";
  const arrivalTime = to
    ? schedule?.to_station_arrival_time
    : schedule?.destination_arrival_time;

  // Estimated position from the timetable: index of the last stop the train
  // has reached, and whether it is still standing there or on its way.
  const clock = useJakartaClock();
  const position = (() => {
    if (!schedule || !stops || !clock) return null;
    const secs = stops.map((stop) => {
      // Times earlier than the departure are after midnight.
      const overnight = stop.time < schedule.departure_time ? 86_400 : 0;
      return secondsUntil(schedule.service_date, stop.time, clock) + overnight;
    });
    const reached = secs.findLastIndex((sec) => sec <= 0);
    if (reached < 0) return { state: "waiting" as const, index: -1, secs };
    if (reached === stops.length - 1 && secs[reached] < 0)
      return { state: "arrived" as const, index: reached, secs };
    if (secs[reached] === 0)
      return { state: "at" as const, index: reached, secs };
    // Between `reached` and the next stop: how far along that stretch.
    const span = secs[reached + 1] - secs[reached];
    return {
      state: "moving" as const,
      index: reached,
      secs,
      fraction: span > 0 ? -secs[reached] / span : 0,
    };
  })();
  const positionText = (() => {
    if (!position || !stops) return null;
    const name = (i: number) => stops[i].station.name;
    switch (position.state) {
      case "waiting":
        return `Kereta belum berangkat dari stasiun ${name(0)}.`;
      case "arrived":
        return `Kereta sudah tiba di stasiun ${name(stops.length - 1)}.`;
      case "at":
        return stops[position.index].is_transit
          ? `Kereta transit di stasiun ${name(position.index)}.`
          : `Kereta berhenti di stasiun ${name(position.index)}.`;
      case "moving":
        return `Kereta dalam perjalanan ke stasiun ${name(position.index + 1)}.`;
    }
  })();

  // No auto-scrolling. While the station list can still scroll further down,
  // an arrow button scrolls it down a page.
  // Desktop: the horizontal timeline gets left/right arrows instead.
  const scrollRef = useRef<HTMLDivElement>(null);
  const hScrollRef = useRef<HTMLDivElement>(null);
  const [moreBelow, setMoreBelow] = useState(false);
  const [moreLeft, setMoreLeft] = useState(false);
  const [moreRight, setMoreRight] = useState(false);
  const checkMoreBelow = () => {
    const box = scrollRef.current;
    if (box)
      setMoreBelow(box.scrollTop + box.clientHeight < box.scrollHeight - 2);
    const h = hScrollRef.current;
    if (h) {
      setMoreLeft(h.scrollLeft > 2);
      setMoreRight(h.scrollLeft + h.clientWidth < h.scrollWidth - 2);
    }
  };
  useEffect(() => {
    if (!open) return;
    // Re-check when a list (or the dialog) changes size.
    const observer = new ResizeObserver(checkMoreBelow);
    for (const box of [scrollRef.current, hScrollRef.current]) {
      if (!box) continue;
      observer.observe(box);
      if (box.firstElementChild) observer.observe(box.firstElementChild);
    }
    return () => observer.disconnect();
  }, [open, stops?.length, loading]);
  const scrollDown = () => {
    const box = scrollRef.current;
    box?.scrollBy({ top: box.clientHeight * 0.8, behavior: "smooth" });
  };
  const scrollSideways = (dir: 1 | -1) => {
    const box = hScrollRef.current;
    box?.scrollBy({ left: dir * box.clientWidth * 0.8, behavior: "smooth" });
  };

  const { isBoarded, toggle } = useBoardedTrain();
  const boarded = schedule ? isBoarded(schedule) : false;

  return (
    <dialog
      ref={ref}
      aria-labelledby="trip-dialog-title"
      className="m-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-md overflow-hidden lg:max-w-4xl rounded-none open:flex open:flex-col border border-line bg-white p-0 text-ink shadow-xl backdrop:bg-ink/60 backdrop:backdrop-blur-sm"
      onCancel={(e) => {
        e.preventDefault();
        onClose();
      }}
      onClick={(e) => e.target === ref.current && onClose()}
    >
      {schedule && (
        <div className="flex min-h-0 flex-1 flex-col p-4 sm:p-6">
          {/* Fixed part: title, train and times stay in view. */}
          <div className="flex shrink-0 items-start justify-between gap-3">
            <div className="min-w-0">
              <h2
                id="trip-dialog-title"
                className="text-base font-bold leading-snug tracking-tight sm:text-lg"
              >
                {from.name} → {toName}
              </h2>
              <p className="mt-0.5 text-xs text-muted sm:text-[13px]">
                KA {schedule.train_number} · {lineLabel(schedule.line?.name)}
              </p>
            </div>
            <button
              type="button"
              onClick={onClose}
              aria-label="Tutup"
              className="-mr-1 -mt-1 shrink-0 rounded-lg p-1.5 text-muted transition-colors hover:bg-slate-100 hover:text-ink"
            >
              <svg
                viewBox="0 0 20 20"
                className="h-5 w-5"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinecap="round"
                aria-hidden="true"
              >
                <path d="M5 5l10 10M15 5L5 15" />
              </svg>
            </button>
          </div>

          {boarded && schedule && (
            <div
              role="status"
              className="mt-3 flex shrink-0 items-center justify-between gap-3 bg-brand-600 px-3 py-2 text-xs font-semibold text-white sm:text-[13px]"
            >
              <span>✓ Anda naik kereta ini</span>
              <button
                type="button"
                onClick={() => toggle({ schedule, from, to })}
                className="shrink-0 border border-white/60 px-2.5 py-0.5 transition-colors hover:bg-white/15"
              >
                Batalkan
              </button>
            </div>
          )}

          {/* Desktop: the train card and the position message side by side. */}
          <div className="shrink-0 lg:mt-4 lg:grid lg:grid-cols-2 lg:gap-4">
            {/* The train on the left, its times on the right. */}
            <div className="mt-4 flex lg:mt-0 items-center gap-3 rounded-none bg-slate-50 p-3 sm:gap-5 sm:p-4">
              <KrlFront
                destination={schedule.destination}
                className="h-auto w-32 shrink-0 sm:w-40"
              />
              <div className="min-w-0 flex-1 space-y-2.5">
                <div>
                  <p className="text-[11px] font-medium text-muted sm:text-xs">
                    Berangkat dari {from.name}
                  </p>
                  <p className="tabular text-xl font-extrabold leading-tight sm:text-2xl">
                    {schedule.departure_time}
                  </p>
                </div>
                <div>
                  <p className="text-[11px] font-medium text-muted sm:text-xs">
                    Tiba di {toName}
                  </p>
                  <p className="tabular text-xl font-extrabold leading-tight sm:text-2xl">
                    {arrivalTime ?? "—"}{" "}
                    <span className="text-[11px] font-medium text-muted sm:text-xs">
                      WIB
                    </span>
                  </p>
                </div>
                {to && (
                  <p className="text-xs text-slate-600">
                    Tujuan akhir{" "}
                    <span className="font-semibold text-ink">
                      {schedule.destination}
                    </span>
                  </p>
                )}
              </div>
            </div>

            {/* Where the train is now, estimated from the timetable. */}
            {positionText && (
              <div
                role="status"
                className="mt-4 flex items-start gap-2.5 border border-brand-600/25 bg-brand-50 px-3 py-2.5 lg:mt-0 lg:self-start"
              >
                <span
                  aria-hidden="true"
                  className="mt-1.5 h-2 w-2 shrink-0 animate-pulse rounded-full bg-brand-600"
                />
                <div className="min-w-0">
                  <p className="text-sm font-semibold text-ink">
                    {positionText}
                  </p>
                  <p className="mt-0.5 text-[11px] text-muted">
                    Perkiraan berdasarkan jadwal
                  </p>
                </div>
              </div>
            )}
          </div>

          <h3 className="mt-5 shrink-0 text-sm font-bold">Rute Perjalanan</h3>
          {/* Only the station list scrolls. */}
          <div className="relative -mx-2 mt-3 flex min-h-0 flex-1 flex-col lg:hidden">
            <div
              ref={scrollRef}
              onScroll={checkMoreBelow}
              className="min-h-0 flex-1 overflow-y-auto overscroll-contain px-2"
            >
              {loading && !data ? (
                <div className="space-y-3" aria-busy="true">
                  {Array.from({ length: 4 }).map((_, i) => (
                    <Skeleton key={i} className="h-5 w-full" />
                  ))}
                </div>
              ) : error ? (
                <ErrorState message={errorMessage(error)} onRetry={reload} />
              ) : stops ? (
                <ol>
                  {stops.map((stop, i) => {
                    const { edge, passed, here, moving, segmentPassed } =
                      stopState(i, stops.length, position);
                    return (
                      <Fragment key={stop.sequence}>
                        <li
                          data-stop={i}
                          className="relative flex gap-3 pb-4 last:pb-0"
                        >
                          {/* Timeline: a line between the dots, filled dots at both ends. */}
                          {i < stops.length - 1 && (
                            <span
                              aria-hidden="true"
                              className={cx(
                                "absolute left-[5px] top-3 h-full w-0.5",
                                segmentPassed ? "bg-slate-300" : "bg-slate-200",
                              )}
                            />
                          )}
                          {/* The train standing at this stop, on top of its dot. */}
                          {here && <TrainMarker />}
                          <span
                            aria-hidden="true"
                            className={cx(
                              "relative mt-1 h-3 w-3 shrink-0 rounded-full border-2",
                              dotClass(stopState(i, stops.length, position)),
                            )}
                          />
                          <div className="flex min-w-0 flex-1 items-baseline justify-between gap-3">
                            <p
                              className={cx(
                                "min-w-0 text-sm",
                                passed
                                  ? "text-slate-400"
                                  : edge || here
                                    ? "font-bold text-ink"
                                    : "text-slate-700",
                                passed && edge && "font-bold",
                              )}
                            >
                              {stop.station.name}
                              {here && (
                                <span className="ml-1.5 text-[11px] font-semibold text-brand-600">
                                  kereta di sini
                                </span>
                              )}
                              {i === 0 && (
                                <span className="ml-1.5 text-[11px] font-medium text-muted">
                                  berangkat
                                </span>
                              )}
                              {i === stops.length - 1 && (
                                <span className="ml-1.5 text-[11px] font-medium text-muted">
                                  tiba
                                </span>
                              )}
                              {stop.is_transit && (
                                <span
                                  className={cx(
                                    "ml-1.5 rounded bg-slate-100 px-1 py-0.5 text-[10px] font-semibold",
                                    passed
                                      ? "text-slate-400"
                                      : "text-slate-600",
                                  )}
                                >
                                  Transit
                                </span>
                              )}
                            </p>
                            <p
                              className={cx(
                                "tabular shrink-0 text-sm",
                                passed
                                  ? "text-slate-400"
                                  : edge || here
                                    ? "font-bold text-ink"
                                    : "text-slate-600",
                              )}
                            >
                              {stop.time}
                            </p>
                          </div>
                        </li>
                        {/* The train on its way: its own row between this stop and the next. */}
                        {moving && (
                          <li className="relative flex items-center gap-3 pb-4">
                            {/* Behind the train: travelled. Ahead of it, up to the next station: flowing. */}
                            <span
                              aria-hidden="true"
                              className="absolute left-[5px] top-0 h-2 w-0.5 bg-slate-300"
                            />
                            <span
                              aria-hidden="true"
                              className="line-flow absolute bottom-0 left-[5px] top-2 w-0.5"
                            />
                            <span className="relative h-4 w-3 shrink-0">
                              <TrainMarker top={8} />
                            </span>
                            <p className="text-xs font-semibold text-brand-600">
                              Kereta dalam perjalanan menuju{" "}
                              {stops[i + 1].station.name}
                            </p>
                          </li>
                        )}
                      </Fragment>
                    );
                  })}
                </ol>
              ) : (
                <p className="rounded-none bg-slate-50 px-4 py-5 text-center text-sm text-muted">
                  Daftar stasiun untuk kereta ini belum tersedia.
                </p>
              )}
            </div>
            {moreBelow && (
              <button
                type="button"
                onClick={scrollDown}
                aria-label="Gulir ke bawah"
                className="absolute bottom-2 left-1/2 flex h-8 w-8 -translate-x-1/2 items-center justify-center rounded-full bg-brand-600/70 text-white shadow-lg backdrop-blur-sm transition-colors hover:bg-brand-600"
              >
                <svg
                  viewBox="0 0 20 20"
                  className="h-4 w-4"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="2.25"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  aria-hidden="true"
                >
                  <path d="M10 4v12M5 11l5 5 5-5" />
                </svg>
              </button>
            )}
          </div>

          {/* Desktop: the route as a horizontal timeline. */}
          <div className="relative -mx-2 mt-3 hidden shrink-0 lg:block">
            <div
              ref={hScrollRef}
              onScroll={checkMoreBelow}
              // A vertical mouse wheel scrolls the timeline sideways.
              onWheel={(e) => {
                if (Math.abs(e.deltaY) > Math.abs(e.deltaX))
                  e.currentTarget.scrollLeft += e.deltaY;
              }}
              className="overflow-x-auto overscroll-contain px-2 pb-3"
            >
              {loading && !data ? (
                <div className="flex gap-4" aria-busy="true">
                  {Array.from({ length: 6 }).map((_, i) => (
                    <Skeleton key={i} className="h-16 w-24" />
                  ))}
                </div>
              ) : error ? (
                <ErrorState message={errorMessage(error)} onRetry={reload} />
              ) : stops ? (
                <ol className="flex w-max">
                  {stops.map((stop, i) => {
                    const st = stopState(i, stops.length, position);
                    const prev =
                      i > 0 ? stopState(i - 1, stops.length, position) : null;
                    return (
                      <Fragment key={stop.sequence}>
                        <li className="relative w-28 shrink-0 px-1 pt-1 text-center">
                          {/* Line halves: in from the previous stop, out to the next one. */}
                          {prev && !prev.moving && (
                            <span
                              aria-hidden="true"
                              className={cx(
                                "absolute left-0 top-[9px] h-0.5 w-1/2",
                                prev.segmentPassed
                                  ? "bg-slate-300"
                                  : "bg-slate-200",
                              )}
                            />
                          )}
                          {prev?.moving && (
                            <span
                              aria-hidden="true"
                              className="line-flow-x absolute left-0 top-[9px] h-0.5 w-1/2"
                            />
                          )}
                          {i < stops.length - 1 && (
                            <span
                              aria-hidden="true"
                              className={cx(
                                "absolute left-1/2 top-[9px] h-0.5 w-1/2",
                                st.segmentPassed
                                  ? "bg-slate-300"
                                  : "bg-slate-200",
                              )}
                            />
                          )}
                          <span className="relative mx-auto block h-3 w-3">
                            <span
                              aria-hidden="true"
                              className={cx(
                                "block h-3 w-3 rounded-full border-2",
                                dotClass(st),
                              )}
                            />
                            {st.here && <TrainMarker top={6} />}
                          </span>
                          <p
                            className={cx(
                              "mt-2 line-clamp-2 text-xs leading-tight",
                              st.passed
                                ? "text-slate-400"
                                : st.edge || st.here
                                  ? "font-bold text-ink"
                                  : "text-slate-700",
                              st.passed && st.edge && "font-bold",
                            )}
                          >
                            {stop.station.name}
                          </p>
                          <p
                            className={cx(
                              "tabular mt-0.5 text-xs",
                              st.passed
                                ? "text-slate-400"
                                : st.edge || st.here
                                  ? "font-bold text-ink"
                                  : "text-slate-600",
                            )}
                          >
                            {stop.time}
                          </p>
                          <div className="mt-1 flex flex-wrap justify-center gap-1">
                            {st.here && (
                              <span className="text-[10px] font-semibold text-brand-600">
                                kereta di sini
                              </span>
                            )}
                            {i === 0 && (
                              <span className="text-[10px] font-medium text-muted">
                                berangkat
                              </span>
                            )}
                            {i === stops.length - 1 && (
                              <span className="text-[10px] font-medium text-muted">
                                tiba
                              </span>
                            )}
                            {stop.is_transit && (
                              <span
                                className={cx(
                                  "rounded bg-slate-100 px-1 py-0.5 text-[10px] font-semibold",
                                  st.passed
                                    ? "text-slate-400"
                                    : "text-slate-600",
                                )}
                              >
                                Transit
                              </span>
                            )}
                          </div>
                        </li>
                        {/* The train on its way: its own column between this stop and the next. */}
                        {st.moving && (
                          <li
                            className="relative w-12 shrink-0 pt-1"
                            title={`Kereta dalam perjalanan menuju ${stops[i + 1].station.name}`}
                          >
                            <span
                              aria-hidden="true"
                              className="absolute left-0 top-[9px] h-0.5 w-1/2 bg-slate-300"
                            />
                            <span
                              aria-hidden="true"
                              className="line-flow-x absolute left-1/2 top-[9px] h-0.5 w-1/2"
                            />
                            <span className="relative mx-auto block h-3 w-3">
                              <TrainMarker top={6} />
                            </span>
                          </li>
                        )}
                      </Fragment>
                    );
                  })}
                </ol>
              ) : (
                <p className="bg-slate-50 px-4 py-5 text-center text-sm text-muted">
                  Daftar stasiun untuk kereta ini belum tersedia.
                </p>
              )}
            </div>
            {moreLeft && (
              <ScrollArrow
                direction="left"
                onClick={() => scrollSideways(-1)}
                className="left-1"
              />
            )}
            {moreRight && (
              <ScrollArrow
                direction="right"
                onClick={() => scrollSideways(1)}
                className="right-1"
              />
            )}
          </div>
        </div>
      )}
    </dialog>
  );
}

type TrainPosition = {
  state: "waiting" | "arrived" | "at" | "moving";
  index: number;
};

/** How one stop (and the stretch after it) looks, given the train's position. */
function stopState(i: number, count: number, position: TrainPosition | null) {
  const edge = i === 0 || i === count - 1;
  // Stations the train has already left are greyed out.
  const passed =
    !!position &&
    position.state !== "waiting" &&
    (i < position.index || (i === position.index && position.state !== "at"));
  const here = position?.state === "at" && position.index === i;
  // The train is on its way from this stop to the next one.
  const moving = position?.state === "moving" && position.index === i;
  // The station the train is heading to: its dot is outlined in red.
  const next = position?.state === "moving" && position.index === i - 1;
  // The stretch from this stop to the next one has been travelled.
  const segmentPassed =
    !!position &&
    position.state !== "waiting" &&
    (i < position.index || moving);
  return { edge, passed, here, moving, next, segmentPassed };
}

function dotClass(s: ReturnType<typeof stopState>) {
  return s.here
    ? "border-brand-600 bg-brand-600"
    : s.next
      ? "border-brand-600 bg-white"
      : s.passed
        ? "border-slate-300 bg-slate-300"
        : s.edge
          ? "border-brand-600 bg-brand-600"
          : "border-slate-300 bg-white";
}

/** Small round train badge, pulsing, centred on a 12px timeline dot (positioned parent: the stop row or dot box). */
function TrainMarker({ top = 10 }: { top?: number }) {
  return (
    <span
      aria-hidden="true"
      style={{ top }}
      className="absolute left-[6px] z-10 flex h-5 w-5 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full bg-brand-600 text-white shadow-md ring-2 ring-white"
    >
      <span className="absolute inset-0 animate-ping rounded-full bg-brand-600/50" />
      <svg
        viewBox="0 0 24 24"
        className="relative h-3 w-3"
        fill="none"
        stroke="currentColor"
        strokeWidth="2"
        strokeLinecap="round"
        strokeLinejoin="round"
      >
        <rect x="5" y="3" width="14" height="14" rx="3" />
        <path d="M5 11h14" />
        <path d="M9 14.5h.01M15 14.5h.01" />
        <path d="M8 21l2-4M16 21l-2-4" />
      </svg>
    </span>
  );
}

/** Round, semi-transparent arrow button over the edge of the horizontal timeline. */
function ScrollArrow({
  direction,
  onClick,
  className,
}: {
  direction: "left" | "right";
  onClick: () => void;
  className: string;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-label={direction === "left" ? "Gulir ke kiri" : "Gulir ke kanan"}
      className={cx(
        "absolute top-0 flex h-8 w-8 items-center justify-center rounded-full bg-brand-600/70 text-white shadow-lg backdrop-blur-sm transition-colors hover:bg-brand-600",
        className,
      )}
    >
      <svg
        viewBox="0 0 20 20"
        className="h-4 w-4"
        fill="none"
        stroke="currentColor"
        strokeWidth="2.25"
        strokeLinecap="round"
        strokeLinejoin="round"
        aria-hidden="true"
      >
        <path
          d={
            direction === "left"
              ? "M16 10H4M9 5l-5 5 5 5"
              : "M4 10h12M11 5l5 5-5 5"
          }
        />
      </svg>
    </button>
  );
}
