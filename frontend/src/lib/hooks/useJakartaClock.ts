"use client";

import { useEffect, useState } from "react";
import { jakartaClock, type JakartaClock } from "@/lib/format";

/** The WIB clock truncated to the whole minute (countdowns count in minutes). */
function minuteClock(): JakartaClock {
  const clock = jakartaClock();
  return { ...clock, seconds: clock.seconds - (clock.seconds % 60) };
}

/**
 * Live WIB clock for countdowns, updated once per minute exactly when the
 * minute changes. Null until mounted to avoid hydration mismatches.
 */
export function useJakartaClock(): JakartaClock | null {
  const [clock, setClock] = useState<JakartaClock | null>(null);

  useEffect(() => {
    let timeout: ReturnType<typeof setTimeout>;
    const tick = () => {
      setClock(minuteClock());
      // Wake up right after the next minute boundary.
      timeout = setTimeout(tick, 60_000 - (Date.now() % 60_000) + 50);
    };
    tick();
    return () => clearTimeout(timeout);
  }, []);

  return clock;
}
