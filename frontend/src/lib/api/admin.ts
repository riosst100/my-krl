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

// --- Stations API URL (source of the monthly station sync) --------------------

export interface StationsApiSetting {
  url: string;
  default_url: string;
  is_default: boolean;
  updated_at: string | null;
  updated_by: string | null;
}

export interface StationsApiPreview {
  ok: boolean;
  url: string;
  count: number;
  sample: { code: string; name: string; enabled: boolean; operational_area: number | null }[];
  error: string | null;
}

export async function getStationsApiSetting(): Promise<StationsApiSetting> {
  return (await apiFetch<{ data: StationsApiSetting }>("/admin/settings/stations-api")).data;
}

export async function saveStationsApiUrl(url: string): Promise<StationsApiSetting> {
  return (await apiFetch<{ data: StationsApiSetting }>("/admin/settings/stations-api", { method: "PUT", body: { url } })).data;
}

export async function resetStationsApiUrl(): Promise<StationsApiSetting> {
  return (await apiFetch<{ data: StationsApiSetting }>("/admin/settings/stations-api", { method: "DELETE" })).data;
}

/** Fetch + parse the URL on the server without saving anything. */
export async function testStationsApiUrl(url: string): Promise<StationsApiPreview> {
  return (await apiFetch<{ data: StationsApiPreview }>("/admin/settings/stations-api/test", { method: "POST", body: { url } })).data;
}

// --- Schedules -------------------------------------------------------------------

export function getAdminSchedules(filters: ScheduleFilters) {
  return apiFetch<Paginated<Schedule, { date: string }>>("/admin/schedules", { query: { ...filters } });
}

// --- Sync ------------------------------------------------------------------------

export interface SyncLogsMeta {
  in_progress: boolean;
  /** The last "Sync dari KCI" (manual or automatic). */
  last_sync: SyncLog | null;
  last_successful_sync: SyncLog | null;
}

export function getSyncLogs(page = 1, type: "" | "schedules" | "stations" = "") {
  return apiFetch<Paginated<SyncLog, SyncLogsMeta>>("/admin/sync-logs", { query: { page, type } });
}

// --- Schedules API URL (source of the daily schedule sync) ---------------------

export interface SchedulesApiSetting extends StationsApiSetting {
  /** Station codes whose timetable is synced (KCI_SYNC_STATIONS); empty = all active stations. */
  sync_stations: string[];
  test_station: string;
}

export interface SchedulesApiPreview {
  ok: boolean;
  url: string;
  station: string;
  count: number;
  first: string | null;
  last: string | null;
  lines: string[];
  sample: {
    train_number: string;
    line: string;
    route_name: string | null;
    destination: string;
    departure_time: string;
    destination_arrival_time: string | null;
  }[];
  error: string | null;
}

export async function getSchedulesApiSetting(): Promise<SchedulesApiSetting> {
  return (await apiFetch<{ data: SchedulesApiSetting }>("/admin/settings/schedules-api")).data;
}

export async function saveSchedulesApiUrl(url: string): Promise<SchedulesApiSetting> {
  return (await apiFetch<{ data: SchedulesApiSetting }>("/admin/settings/schedules-api", { method: "PUT", body: { url } })).data;
}

export async function resetSchedulesApiUrl(): Promise<SchedulesApiSetting> {
  return (await apiFetch<{ data: SchedulesApiSetting }>("/admin/settings/schedules-api", { method: "DELETE" })).data;
}

// --- Train Stops API URL (stops per train, synced with the schedules) ---------

export interface TrainStopsApiPreview {
  ok: boolean;
  url: string;
  train: string;
  count: number;
  stops: { station_code: string; time: string; is_transit: boolean }[];
  error: string | null;
}

export async function getTrainStopsApiSetting(): Promise<StationsApiSetting> {
  return (await apiFetch<{ data: StationsApiSetting }>("/admin/settings/train-stops-api")).data;
}

export async function saveTrainStopsApiUrl(url: string): Promise<StationsApiSetting> {
  return (await apiFetch<{ data: StationsApiSetting }>("/admin/settings/train-stops-api", { method: "PUT", body: { url } })).data;
}

export async function resetTrainStopsApiUrl(): Promise<StationsApiSetting> {
  return (await apiFetch<{ data: StationsApiSetting }>("/admin/settings/train-stops-api", { method: "DELETE" })).data;
}

export async function testTrainStopsApiUrl(url: string, train?: string): Promise<TrainStopsApiPreview> {
  return (await apiFetch<{ data: TrainStopsApiPreview }>("/admin/settings/train-stops-api/test", { method: "POST", body: { url, train } })).data;
}

/** Fetch + parse one station's timetable on the server without saving. */
export async function testSchedulesApiUrl(url: string, station?: string): Promise<SchedulesApiPreview> {
  return (
    await apiFetch<{ data: SchedulesApiPreview }>("/admin/settings/schedules-api/test", { method: "POST", body: { url, station } })
  ).data;
}

/** "Sync dari KCI": fetch from KCI straight into the database (runs in the background). */
export async function triggerKciSync(): Promise<SyncLog> {
  const res = await apiFetch<{ data: SyncLog }>("/admin/sync/kci", { method: "POST" });
  return res.data;
}

// --- Automatic sync times ---------------------------------------------------------

export interface AutoSyncSetting {
  /** Times of day ("HH:MM", sorted) in effect; empty = off. */
  times: string[];
  /** KCI_AUTO_SYNC_TIMES from the environment. */
  default_times: string[];
  is_default: boolean;
  timezone: string;
  next_run_at: string | null;
  last_run: SyncLog | null;
  scheduler_seen_at: string | null;
  scheduler_running: boolean;
  updated_at: string | null;
  updated_by: string | null;
}

export async function getAutoSyncSetting(): Promise<AutoSyncSetting> {
  return (await apiFetch<{ data: AutoSyncSetting }>("/admin/settings/auto-sync")).data;
}

export async function saveAutoSyncTimes(times: string[]): Promise<AutoSyncSetting> {
  return (await apiFetch<{ data: AutoSyncSetting }>("/admin/settings/auto-sync", { method: "PUT", body: { times } })).data;
}

export async function resetAutoSyncTimes(): Promise<AutoSyncSetting> {
  return (await apiFetch<{ data: AutoSyncSetting }>("/admin/settings/auto-sync", { method: "DELETE" })).data;
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

// --- Stations whose schedules are synced -----------------------------------------

export interface SyncStationsSetting {
  /** Station codes in effect (empty = every active station). */
  stations: string[];
  /** KCI_SYNC_STATIONS from the environment. */
  default_stations: string[];
  is_default: boolean;
  active_stations: number;
  updated_at: string | null;
  updated_by: string | null;
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
