import { apiFetch, refreshCsrfCookie } from "./client";
import type { DashboardStats, Paginated, Schedule, ScheduleFilters, Station, StationDetail, SyncLog, User, UserRole } from "./types";

// --- Admin auth (separate "admin" session guard on the backend) -----------

export async function adminLogin(email: string, password: string): Promise<User> {
  await refreshCsrfCookie();
  const res = await apiFetch<{ data: User }>("/admin/auth/login", { method: "POST", body: { email, password } });
  return res.data;
}

export function adminLogout() {
  return apiFetch<void>("/admin/auth/logout", { method: "POST" });
}

export async function getCurrentAdmin(): Promise<User> {
  const res = await apiFetch<{ data: User }>("/admin/auth/me");
  return res.data;
}

// --- Dashboard ---------------------------------------------------------------

export async function getDashboard(): Promise<DashboardStats> {
  const res = await apiFetch<{ data: DashboardStats }>("/admin/dashboard");
  return res.data;
}

// --- Users ---------------------------------------------------------------------

export function getAdminUsers(params: { search?: string; role?: UserRole | ""; page?: number }) {
  return apiFetch<Paginated<User>>("/admin/users", { query: params });
}

export async function getAdminUser(id: number | string): Promise<User> {
  const res = await apiFetch<{ data: User }>(`/admin/users/${id}`);
  return res.data;
}

export async function updateUserRole(id: number, role: UserRole): Promise<User> {
  const res = await apiFetch<{ data: User }>(`/admin/users/${id}/role`, { method: "PATCH", body: { role } });
  return res.data;
}

// --- Stations --------------------------------------------------------------------

export interface AdminStationsMeta {
  last_station_sync: SyncLog | null;
  last_successful_station_sync: SyncLog | null;
  station_sync_in_progress: boolean;
}

export function getAdminStations(params: { search?: string; status?: "" | "active" | "inactive"; page?: number; per_page?: number }) {
  return apiFetch<Paginated<Station, AdminStationsMeta>>("/admin/stations", { query: params });
}

export async function getAdminStation(id: number | string): Promise<StationDetail> {
  const res = await apiFetch<{ data: StationDetail }>(`/admin/stations/${id}`);
  return res.data;
}

export async function updateStation(
  id: number,
  data: { is_active?: boolean; latitude?: number | null; longitude?: number | null },
): Promise<Station> {
  const res = await apiFetch<{ data: Station }>(`/admin/stations/${id}`, { method: "PATCH", body: data });
  return res.data;
}

export function setStationActive(id: number, isActive: boolean): Promise<Station> {
  return updateStation(id, { is_active: isActive });
}

// --- Schedules -------------------------------------------------------------------

export function getAdminSchedules(filters: ScheduleFilters) {
  return apiFetch<Paginated<Schedule, { date: string }>>("/admin/schedules", { query: { ...filters } });
}

// --- Sync ------------------------------------------------------------------------

/** Kinds of sync runs (krl-sync on Vercel, or a manual import). */
export type KciSyncType = "stations" | "schedules" | "trains";

export interface SyncLogsMeta {
  in_progress: boolean;
  /** The last sync (krl-sync on Vercel or a manual import) of the requested type (default: schedules). */
  last_sync: SyncLog | null;
  last_successful_sync: SyncLog | null;
}

export function getSyncLogs(page = 1, type: "" | KciSyncType = "") {
  return apiFetch<Paginated<SyncLog, SyncLogsMeta>>("/admin/sync-logs", { query: { page, type } });
}

export type ImportType = "schedules" | "train_stops" | "stations";

export interface ImportInput {
  type: ImportType;
  /** Station code (schedules only). */
  station?: string;
  /** Train number (train stops; optional when the rows carry "train_id"). */
  train?: string;
  /** The pasted KCI API response. */
  json: string;
}

/** Manual import of a pasted KCI API response (no KCI access needed). */
export async function importKciJson(input: ImportInput): Promise<{ type: ImportType; records: number; message: string }> {
  return (await apiFetch<{ data: { type: ImportType; records: number; message: string } }>("/admin/sync/import", { method: "POST", body: input })).data;
}

// --- krl-sync (Vercel): stations it syncs, and its automatic sync ---------------

export interface SyncStationsSetting {
  /** Station codes in effect (empty = every active station). */
  stations: string[];
  /** KCI_SYNC_STATIONS from the environment. */
  default_stations: string[];
  is_default: boolean;
  active_stations: number;
  updated_at: string | null;
  updated_by: string | null;
  /** Whether krl-sync's daily automatic sync (Vercel Cron) runs. */
  auto_sync: boolean;
}

export async function getSyncStationsSetting(): Promise<SyncStationsSetting> {
  return (await apiFetch<{ data: SyncStationsSetting }>("/admin/settings/sync-stations")).data;
}

export async function saveSyncStations(stations: string[]): Promise<SyncStationsSetting> {
  return (await apiFetch<{ data: SyncStationsSetting }>("/admin/settings/sync-stations", { method: "PUT", body: { stations } })).data;
}

export async function resetSyncStations(): Promise<SyncStationsSetting> {
  return (await apiFetch<{ data: SyncStationsSetting }>("/admin/settings/sync-stations", { method: "DELETE" })).data;
}

/** Switches krl-sync's daily automatic sync on or off (manual syncs keep working). */
export async function setKrlSyncAutoSync(enabled: boolean): Promise<SyncStationsSetting> {
  return (await apiFetch<{ data: SyncStationsSetting }>("/admin/settings/ingest-auto-sync", { method: "PUT", body: { enabled } })).data;
}
