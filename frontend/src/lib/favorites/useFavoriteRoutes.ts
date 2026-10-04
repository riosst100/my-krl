"use client";

import { useCallback, useEffect, useState } from "react";
import { type FavoriteRoute, getFavoriteRoutes, type RouteInput, saveFavoriteRoutes } from "@/lib/api/favorites";
import { useAuth } from "@/lib/auth/AuthProvider";

export type FavoritesStatus = "loading" | "guest" | "ready";

/**
 * The signed-in user's favourite routes (1 to 4, departure -> destination),
 * stored on the account. Guests have none.
 */
export function useFavoriteRoutes() {
  const { status: authStatus, user } = useAuth();
  const [routes, setRoutes] = useState<FavoriteRoute[]>([]);
  const [status, setStatus] = useState<FavoritesStatus>("loading");
  const [error, setError] = useState(false);

  useEffect(() => {
    if (authStatus === "loading") return;
    let cancelled = false;

    /* eslint-disable react-hooks/set-state-in-effect -- sync with the auth state */
    if (authStatus !== "authenticated") {
      setRoutes([]);
      setStatus("guest");
      return;
    }

    setStatus("loading");
    /* eslint-enable react-hooks/set-state-in-effect */
    getFavoriteRoutes()
      .then((saved) => {
        if (!cancelled) {
          setRoutes(saved);
          setError(false);
        }
      })
      .catch(() => {
        if (!cancelled) setError(true);
      })
      .finally(() => {
        if (!cancelled) setStatus("ready");
      });

    return () => {
      cancelled = true;
    };
  }, [authStatus, user?.id]);

  const save = useCallback(async (next: RouteInput[]) => {
    setRoutes(await saveFavoriteRoutes(next));
    setError(false);
  }, []);

  return {
    routes,
    status,
    error,
    /** Signed in, but no favourite route yet: the dialog asks for one. */
    missing: status === "ready" && !error && routes.length === 0,
    save,
  };
}
