import { apiFetch } from "./client";
import type { Station } from "./types";

export async function getStations(search?: string): Promise<Station[]> {
  const res = await apiFetch<{ data: Station[] }>("/stations", {
    query: { search },
    // Station list changes rarely; cache briefly when rendered on the server.
    next: { revalidate: 300 },
  });
  return res.data;
}

/** Accepts a station code (BKS) or slug (bekasi). */
export async function getStation(codeOrSlug: string): Promise<Station> {
  const res = await apiFetch<{ data: Station }>(`/stations/${encodeURIComponent(codeOrSlug)}`, {
    next: { revalidate: 300 },
  });
  return res.data;
}
