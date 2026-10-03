import { apiFetch } from "./client";
import type { Paginated, ReachableStation, Schedule, ScheduleFilters, StationScheduleMeta } from "./types";

export interface StationSchedules {
  data: Schedule[];
  meta: StationScheduleMeta;
}

export function getStationSchedules(stationCode: string, filters: ScheduleFilters = {}, signal?: AbortSignal) {
  return apiFetch<StationSchedules>(`/stations/${encodeURIComponent(stationCode)}/schedules`, {
    query: { ...filters },
    signal,
  });
}

/** Stations reachable from a station on a date (trains that stop there later). */
export async function getStationDestinations(stationCode: string, date: string, signal?: AbortSignal): Promise<ReachableStation[]> {
  const res = await apiFetch<{ data: ReachableStation[] }>(`/stations/${encodeURIComponent(stationCode)}/destinations`, {
    query: { date },
    signal,
  });
  return res.data;
}

export function getSchedules(filters: ScheduleFilters = {}, signal?: AbortSignal) {
  return apiFetch<Paginated<Schedule, { date: string }>>("/schedules", { query: { ...filters }, signal });
}

export async function getAvailableDates(): Promise<{ dates: string[]; today: string }> {
  const res = await apiFetch<{ data: string[]; meta: { today: string } }>("/schedules/dates");
  return { dates: res.data, today: res.meta.today };
}
