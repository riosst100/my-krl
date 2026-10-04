"use client";

import { useEffect, useRef } from "react";
import { KrlFront } from "@/components/favorites/KrlFront";
import { cx, ErrorState, Skeleton } from "@/components/ui";
import { errorMessage } from "@/lib/api/client";
import { getTrainStops } from "@/lib/api/schedules";
import type { Schedule, Station } from "@/lib/api/types";
import { lineLabel } from "@/lib/format";
import { useApi } from "@/lib/hooks/useApi";

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

  return (
    <dialog
      ref={ref}
      aria-labelledby="trip-dialog-title"
      className="m-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-md overflow-hidden rounded-2xl open:flex open:flex-col border border-line bg-white p-0 text-ink shadow-xl backdrop:bg-ink/60 backdrop:backdrop-blur-sm"
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

          {/* The train on the left, its times on the right. */}
          <div className="mt-4 flex shrink-0 items-center gap-3 rounded-xl bg-slate-50 p-3 sm:gap-5 sm:p-4">
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

          <h3 className="mt-5 shrink-0 text-sm font-bold">
            Stasiun yang dilewati
          </h3>
          {/* Only the station list scrolls. */}
          <div className="-mr-2 mt-3 min-h-0 flex-1 overflow-y-auto overscroll-contain pr-2">
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
                  const edge = i === 0 || i === stops.length - 1;
                  return (
                    <li
                      key={stop.sequence}
                      className="relative flex gap-3 pb-4 last:pb-0"
                    >
                      {/* Timeline: a line between the dots, filled dots at both ends. */}
                      {i < stops.length - 1 && (
                        <span
                          aria-hidden="true"
                          className="absolute left-[5px] top-3 h-full w-0.5 bg-slate-200"
                        />
                      )}
                      <span
                        aria-hidden="true"
                        className={cx(
                          "relative mt-1 h-3 w-3 shrink-0 rounded-full border-2",
                          edge
                            ? "border-brand-600 bg-brand-600"
                            : "border-slate-300 bg-white",
                        )}
                      />
                      <div className="flex min-w-0 flex-1 items-baseline justify-between gap-3">
                        <p
                          className={cx(
                            "min-w-0 text-sm",
                            edge ? "font-bold text-ink" : "text-slate-700",
                          )}
                        >
                          {stop.station.name}
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
                            <span className="ml-1.5 rounded bg-slate-100 px-1 py-0.5 text-[10px] font-semibold text-slate-600">
                              Transit
                            </span>
                          )}
                        </p>
                        <p
                          className={cx(
                            "tabular shrink-0 text-sm",
                            edge ? "font-bold text-ink" : "text-slate-600",
                          )}
                        >
                          {stop.time}
                        </p>
                      </div>
                    </li>
                  );
                })}
              </ol>
            ) : (
              <p className="rounded-xl bg-slate-50 px-4 py-5 text-center text-sm text-muted">
                Daftar stasiun untuk kereta ini belum tersedia.
              </p>
            )}
          </div>
        </div>
      )}
    </dialog>
  );
}
