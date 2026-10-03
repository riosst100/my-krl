/** KRL runs on Western Indonesia Time; all dates/times are shown in WIB. */
export const TIMEZONE = "Asia/Jakarta";

/** Today's date in Jakarta as YYYY-MM-DD. */
export function todayInJakarta(): string {
  return new Intl.DateTimeFormat("en-CA", { timeZone: TIMEZONE }).format(new Date());
}

/** Current time in Jakarta as HH:MM. */
export function nowTimeInJakarta(): string {
  return new Intl.DateTimeFormat("en-GB", { timeZone: TIMEZONE, hour: "2-digit", minute: "2-digit", hour12: false }).format(
    new Date(),
  );
}

export function isValidDate(value: string | undefined | null): value is string {
  return !!value && /^\d{4}-\d{2}-\d{2}$/.test(value);
}

/** "2026-10-03" -> "Sabtu, 3 Oktober 2026" */
export function formatDateLong(date: string): string {
  const [y, m, d] = date.split("-").map(Number);
  return new Intl.DateTimeFormat("id-ID", { weekday: "long", day: "numeric", month: "long", year: "numeric", timeZone: "UTC" }).format(
    new Date(Date.UTC(y, m - 1, d)),
  );
}

/** "2026-10-03" -> "3 Okt" */
export function formatDateShort(date: string): string {
  const [y, m, d] = date.split("-").map(Number);
  return new Intl.DateTimeFormat("id-ID", { weekday: "short", day: "numeric", month: "short", timeZone: "UTC" }).format(
    new Date(Date.UTC(y, m - 1, d)),
  );
}

/** ISO timestamp -> "3 Okt 2026, 13.34" in WIB. */
export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return "—";
  return new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "short", timeZone: TIMEZONE }).format(new Date(iso));
}

/** ISO timestamp -> "Sabtu, 3 Oktober 2026 pukul 14.41 WIB". */
export function formatDateTimeLong(iso: string | null | undefined): string {
  if (!iso) return "—";
  const d = new Date(iso);
  const date = new Intl.DateTimeFormat("id-ID", { weekday: "long", day: "numeric", month: "long", year: "numeric", timeZone: TIMEZONE }).format(d);
  const time = new Intl.DateTimeFormat("id-ID", { hour: "2-digit", minute: "2-digit", hour12: false, timeZone: TIMEZONE }).format(d);
  return `${date} pukul ${time} WIB`;
}

export function formatNumber(value: number): string {
  return new Intl.NumberFormat("id-ID").format(value);
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
  const short = name.replace(/^COMMUTER LINE\s*/i, "").toLowerCase().replace(/\b\w/g, (c) => c.toUpperCase());
  return short ? `Line ${short}` : name;
}
