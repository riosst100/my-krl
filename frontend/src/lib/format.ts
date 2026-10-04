/** KRL runs on Western Indonesia Time; all dates/times are shown in WIB. */
export const TIMEZONE = "Asia/Jakarta";

/** Today's date in Jakarta as YYYY-MM-DD. */
export function todayInJakarta(): string {
  return new Intl.DateTimeFormat("en-CA", { timeZone: TIMEZONE }).format(
    new Date(),
  );
}

/** Current time in Jakarta as HH:MM. */
export function nowTimeInJakarta(): string {
  return new Intl.DateTimeFormat("en-GB", {
    timeZone: TIMEZONE,
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  }).format(new Date());
}

export function isValidDate(value: string | undefined | null): value is string {
  return !!value && /^\d{4}-\d{2}-\d{2}$/.test(value);
}

/** "2026-10-03" -> "Sabtu, 3 Oktober 2026" */
export function formatDateLong(date: string): string {
  const [y, m, d] = date.split("-").map(Number);
  return new Intl.DateTimeFormat("id-ID", {
    weekday: "long",
    day: "numeric",
    month: "long",
    year: "numeric",
    timeZone: "UTC",
  }).format(new Date(Date.UTC(y, m - 1, d)));
}

/** "2026-10-03" -> "3 Okt" */
export function formatDateShort(date: string): string {
  const [y, m, d] = date.split("-").map(Number);
  return new Intl.DateTimeFormat("id-ID", {
    weekday: "short",
    day: "numeric",
    month: "short",
    timeZone: "UTC",
  }).format(new Date(Date.UTC(y, m - 1, d)));
}

/** ISO timestamp -> "3 Okt 2026, 13.34" in WIB. */
export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return "—";
  return new Intl.DateTimeFormat("id-ID", {
    dateStyle: "medium",
    timeStyle: "short",
    timeZone: TIMEZONE,
  }).format(new Date(iso));
}

/** ISO timestamp -> "Sabtu, 3 Oktober 2026 pukul 14.41 WIB". */
export function formatDateTimeLong(iso: string | null | undefined): string {
  if (!iso) return "—";
  const d = new Date(iso);
  const date = new Intl.DateTimeFormat("id-ID", {
    weekday: "long",
    day: "numeric",
    month: "long",
    year: "numeric",
    timeZone: TIMEZONE,
  }).format(d);
  const time = new Intl.DateTimeFormat("id-ID", {
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
    timeZone: TIMEZONE,
  }).format(d);
  return `${date} pukul ${time} WIB`;
}

export function formatNumber(value: number): string {
  return new Intl.NumberFormat("id-ID").format(value);
}

export interface JakartaClock {
  /** YYYY-MM-DD in WIB */
  date: string;
  /** Seconds since midnight in WIB */
  seconds: number;
}

/** The current date and second of the day in Jakarta. */
export function jakartaClock(at: Date = new Date()): JakartaClock {
  const parts = Object.fromEntries(
    new Intl.DateTimeFormat("en-CA", {
      timeZone: TIMEZONE,
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
      hour: "2-digit",
      minute: "2-digit",
      second: "2-digit",
      hourCycle: "h23",
    })
      .formatToParts(at)
      .map((p) => [p.type, p.value]),
  );
  return {
    date: `${parts.year}-${parts.month}-${parts.day}`,
    seconds:
      Number(parts.hour) * 3600 +
      Number(parts.minute) * 60 +
      Number(parts.second),
  };
}

/** Seconds from `clock` until a departure (service date + HH:MM), negative once it has left. */
export function secondsUntil(
  serviceDate: string,
  time: string,
  clock: JakartaClock,
): number {
  const [y, m, d] = serviceDate.split("-").map(Number);
  const [cy, cm, cd] = clock.date.split("-").map(Number);
  const days = Math.round(
    (Date.UTC(y, m - 1, d) - Date.UTC(cy, cm - 1, cd)) / 86_400_000,
  );
  const [hh, mm] = time.split(":").map(Number);
  return days * 86_400 + hh * 3600 + mm * 60 - clock.seconds;
}

/** Departures stay listed up to this long after their time ("berangkat sekarang"). */
export const DEPARTED_GRACE_SECONDS = 60;

/** A train leaving within this many seconds is flagged as urgent (red) in the lists. */
export const URGENT_SECONDS = 300;

/** 4800 -> "1 jam 20 menit lagi", 600 -> "10 menit lagi", 30 -> "1 menit lagi", 0 -> "berangkat sekarang". */
export function formatCountdown(seconds: number): string {
  if (seconds <= -DEPARTED_GRACE_SECONDS) return "sudah berangkat";
  if (seconds <= 0) return "berangkat sekarang";
  const minutes = Math.ceil(seconds / 60);
  if (minutes < 60) return `${minutes} menit lagi`;
  const hours = Math.floor(minutes / 60);
  const rest = minutes % 60;
  return rest === 0 ? `${hours} jam lagi` : `${hours} jam ${rest} menit lagi`;
}

/** Minutes between two HH:MM strings (b - a). */
export function minutesBetween(a: string, b: string): number {
  const [ah, am] = a.split(":").map(Number);
  const [bh, bm] = b.split(":").map(Number);
  return bh * 60 + bm - (ah * 60 + am);
}

/** "COMMUTER LINE BOGOR" -> "Bogor Line" */
export function lineLabel(name: string | undefined | null): string {
  if (!name) return "KRL";
  const short = name
    .replace(/^COMMUTER LINE\s*/i, "")
    .toLowerCase()
    .replace(/\b\w/g, (c) => c.toUpperCase());
  return short ? `Line ${short}` : name;
}

/** First train of the day leaves around this time (WIB). */
export const FIRST_TRAIN_TIME = "04:00";

/**
 * Explains why the list does not start with a train leaving soon: the day's
 * service has ended (the list then shows tomorrow's first trains) or it is
 * still before the first train. Null when trains are running normally.
 */
export function serviceBreakNotice(
  departures: { service_date: string; departure_time: string }[],
  clock: JakartaClock,
): string | null {
  const first = departures[0];
  if (!first) return null;
  if (first.service_date > clock.date) {
    return `Jadwal KRL hari ini selesai sampai jam 12 malam. Kereta mulai berangkat lagi pukul ${first.departure_time} WIB — berikut jadwal besok.`;
  }
  if (clock.seconds < 4 * 3600 && first.departure_time >= FIRST_TRAIN_TIME) {
    return `Belum ada kereta beroperasi. Kereta mulai berangkat pukul ${first.departure_time} WIB.`;
  }
  return null;
}
