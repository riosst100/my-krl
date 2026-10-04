"use client";

import { useSyncExternalStore } from "react";

const subscribe = () => () => {};

/**
 * False during server rendering and before hydration, true once React is
 * running in the browser. Used to keep submit buttons disabled until their
 * handlers are attached: a click before that would submit the form natively
 * and reload the page, wiping what the user typed.
 */
export function useHydrated(): boolean {
  return useSyncExternalStore(
    subscribe,
    () => true,
    () => false,
  );
}
