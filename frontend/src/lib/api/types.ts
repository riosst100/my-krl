export type UserRole = "user" | "admin";

export interface User {
  id: number;
  name: string;
  email: string;
  role: UserRole;
  created_at: string | null;
  updated_at: string | null;
}

export interface Station {
  id: number;
  code: string;
  name: string;
  slug: string;
  latitude: number | null;
  longitude: number | null;
  /** KCI operational area ("group_wil"). */
  operational_area: number | null;
  /** Area name from KCI's area header rows, e.g. "Jabodetabek". */
  operational_area_name: string | null;
  /** Whether KCI lists the station as enabled ("fg_enable"). */
  kci_enabled: boolean;
  /** Admin-controlled visibility on the public site. */
  is_active: boolean;
  synced_at: string | null;
  schedules_count?: number;
  updated_at: string | null;
}

export interface StationDetail extends Station {
  lines: TrainLine[];
  schedules_by_date: { date: string; trains: number; first_departure: string; last_departure: string }[];
  destinations_today: { destination: string; trains: number }[];
  last_schedule_update: string | null;
}

export interface TrainLine {
  id: number;
  name: string;
  color: string | null;
}

/** One train calling at one station. Only fields KCI actually provides. */
export interface Schedule {
  id: number;
  train_number: string;
  line: TrainLine | null;
  /** Colour of this train as published by KCI (may differ from the line colour). */
  color: string | null;
  route_name: string | null;
  destination: string;
  departure_time: string; // HH:MM
  destination_arrival_time: string | null; // HH:MM
  service_date: string; // YYYY-MM-DD
  station?: Station;
  /** Only when searching with a destination station (?to=): arrival time there. */
  to_station_arrival_time?: string;
  /** Number of stops from the departure station to the destination station. */
  stops_to_station?: number;
}

export interface ReachableStation {
  code: string;
  name: string;
  slug: string;
  trains: number;
}

export interface StationScheduleMeta {
  station: Pick<Station, "code" | "name" | "slug">;
  /** Destination station when searching with ?to=. */
  to: Pick<Station, "code" | "name" | "slug"> | null;
  /** Whether per-train stop data exists (needed for destination-station search). */
  stops_available: boolean;
  date: string;
  count: number;
  total_for_date: number;
  destinations: string[];
  lines: TrainLine[];
  last_synced_at: string | null;
}

export interface PaginationMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number | null;
  to: number | null;
}

export interface Paginated<T, M = object> {
  data: T[];
  meta: PaginationMeta & M;
}

export type SyncStatus = "queued" | "running" | "success" | "partial" | "failed";

export type SyncType = "kci_schedules" | "kci_stations" | "prod_push";

export interface SyncProgress {
  phase: "fetch_schedules" | "fetch_stops" | "stations" | "push_schedules" | "push_stops";
  label: string;
  done: number;
  total: number;
  percent: number;
  detail: string | null;
}

export interface SyncLog {
  id: number;
  type: SyncType;
  status: SyncStatus;
  trigger: "schedule" | "manual" | "console" | "ingest" | "push" | "import";
  source: string | null;
  triggered_by: Pick<User, "id" | "name" | "email"> | null;
  records_processed: number;
  stations_processed: number;
  error_message: string | null;
  meta: {
    url?: string | null;
    from?: string;
    days?: number;
    stations?: string[] | "all";
    failed_stations?: Record<string, string>;
    pruned?: number;
    created?: number;
    updated?: number;
    unchanged?: number;
    missing_from_kci?: string[];
    /** Sync to prod: host that received the data. */
    target?: string;
    run_id?: number;
    /** Sync to prod: false = the local data was sent without fetching from KCI first. */
    fetch?: boolean;
    /** Sync to prod: the data is stored locally, so a failed push can be retried without fetching. */
    data_ready?: boolean;
    trains?: number;
    stops?: number;
    progress?: SyncProgress;
    train_stops?: { trains: number; fetched: number; skipped: number; stops: number; failed: number } | null;
  } | null;
  started_at: string | null;
  finished_at: string | null;
  duration_seconds: number | null;
  created_at: string | null;
}

export interface DashboardStats {
  total_users: number;
  total_admins: number;
  total_stations: number;
  active_stations: number;
  total_schedules: number;
  schedules_today: number;
  last_sync: SyncLog | null;
  last_station_sync: SyncLog | null;
}

export interface ScheduleFilters {
  date?: string;
  direction?: string;
  line?: string;
  train_number?: string;
  /** Destination station code or slug. */
  to?: string;
  time_from?: string;
  time_to?: string;
  station?: string;
  page?: number;
  per_page?: number;
}
