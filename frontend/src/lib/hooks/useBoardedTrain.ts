"use client";

import { useCallback, useMemo, useSyncExternalStore } from "react";
import type { Schedule, Station } from "@/lib/api/types";

const KEY = "krl:boarded-train";

export type StationRef = Pick<Station, "code" | "name" | "slug">;

/** The train the visitor boarded, with the stations of the trip they were viewing. */
export interface BoardedTrip {
  schedule: Schedule;
  from: StationRef;
  /** Chosen destination station; null = the train's final destination. */
  to: StationRef | null;
}

type Listener = () => void;
const listeners = new Set<Listener>();

function subscribe(cb: Listener) {
  listeners.add(cb);
  window.addEventListener("storage", cb);
  return () => {
    listeners.delete(cb);
    window.removeEventListener("storage", cb);
  };
}

function read(): string | null {
  try {
    return localStorage.getItem(KEY);
  } catch {
    return null;
  }
}

function sameTrain(a: Schedule, b: Schedule) {
  return a.service_date === b.service_date && a.train_number === b.train_number;
}

/**
 * The one train the visitor marked "Saya sudah naik ini", remembered in this
 * browser (a train is identified by its service date and number).
 */
export function useBoardedTrain() {
  const raw = useSyncExternalStore(subscribe, read, () => null);

  const boarded = useMemo<BoardedTrip | null>(() => {
    if (!raw) return null;
    try {
      const trip = JSON.parse(raw) as BoardedTrip;
      return trip?.schedule && trip.from ? trip : null;
    } catch {
      return null;
    }
  }, [raw]);

  const clear = useCallback(() => {
    try {
      localStorage.removeItem(KEY);
    } catch {
      return;
    }
    listeners.forEach((cb) => cb());
  }, []);

  /** Mark the train as boarded, or unmark it if it already is. */
  const toggle = useCallback((trip: BoardedTrip) => {
    let same = false;
    try {
      const current = read();
      // An unreadable value (e.g. the old format) is simply replaced.
      same =
        !!current &&
        sameTrain((JSON.parse(current) as BoardedTrip).schedule, trip.schedule);
    } catch {
      same = false;
    }
    try {
      if (same) localStorage.removeItem(KEY);
      else localStorage.setItem(KEY, JSON.stringify(trip));
    } catch {
      return;
    }
    listeners.forEach((cb) => cb());
  }, []);

  const isBoarded = useCallback(
    (schedule: Schedule) => !!boarded && sameTrain(boarded.schedule, schedule),
    [boarded],
  );

  return { boarded, isBoarded, toggle, clear };
}
