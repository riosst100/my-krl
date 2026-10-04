import { apiFetch } from "./client";
import type { Schedule, Station } from "./types";

export const MIN_FAVORITE_ROUTES = 1;
export const MAX_FAVORITE_ROUTES = 4;

type StationRef = Pick<Station, "code" | "name" | "slug">;

/** A favourite route: departure station -> destination station. */
export interface FavoriteRoute {
  id: number;
  position: number;
  from: StationRef;
  to: StationRef;
}

export interface RouteInput {
  from: string;
  to: string;
}

/** Signed-in user's favourite routes (server side). */
export async function getFavoriteRoutes(): Promise<FavoriteRoute[]> {
  return (await apiFetch<{ data: FavoriteRoute[] }>("/me/favorite-routes")).data;
}

export async function saveFavoriteRoutes(routes: RouteInput[]): Promise<FavoriteRoute[]> {
  return (await apiFetch<{ data: FavoriteRoute[] }>("/me/favorite-routes", { method: "PUT", body: { routes } })).data;
}

export interface RouteDepartures extends FavoriteRoute {
  has_schedules_today: boolean;
  departures: Schedule[];
}

/** The next trains (from now) of every favourite route. */
export async function getFavoriteRouteDepartures(limit = 2, signal?: AbortSignal) {
  return apiFetch<{ data: RouteDepartures[]; meta: { now: string; last_synced_at: string | null } }>("/me/favorite-routes/departures", {
    query: { limit },
    signal,
  });
}

/** The soonest departures from now, across all stations or from one station (homepage for guests). */
export function getUpcomingDepartures(limit = 5, station?: string, signal?: AbortSignal) {
  return apiFetch<{
    data: Schedule[];
    meta: {
      now: string;
      station: Pick<Station, "code" | "name" | "slug"> | null;
      /** Today's timetable exists (an empty list then means: no more trains today). */
      has_schedules_today: boolean;
      last_synced_at: string | null;
    };
  }>("/schedules/upcoming", {
    query: { limit, station },
    signal,
  });
}

