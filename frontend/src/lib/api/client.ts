/**
 * Single HTTP entry point to the Laravel API.
 *
 * - Browser: cookie-based Sanctum auth (credentials: "include") with the
 *   XSRF-TOKEN cookie echoed back as the X-XSRF-TOKEN header. No tokens are
 *   ever stored in localStorage.
 * - Server (RSC): talks to Laravel over the internal Docker network for
 *   public, unauthenticated data.
 */

const isServer = typeof window === "undefined";

const API_ORIGIN = (
  isServer ? process.env.API_INTERNAL_URL || process.env.NEXT_PUBLIC_API_URL : process.env.NEXT_PUBLIC_API_URL
)?.replace(/\/$/, "") ?? "http://localhost:8000";

const API_PREFIX = "/api/v1";

/** Fired when an authenticated request returns 401 (session expired). */
export const UNAUTHORIZED_EVENT = "krl:unauthorized";
export type AuthScope = "user" | "admin";

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    message: string,
    public readonly errors: Record<string, string[]> = {},
  ) {
    super(message);
    this.name = "ApiError";
  }

  get isUnauthorized() {
    return this.status === 401;
  }

  get isNotFound() {
    return this.status === 404;
  }

  get isValidation() {
    return this.status === 422;
  }

  /** First validation message for a field, if any. */
  field(name: string): string | undefined {
    return this.errors[name]?.[0];
  }
}

type Query = Record<string, string | number | boolean | null | undefined>;

export interface RequestOptions {
  method?: "GET" | "POST" | "PUT" | "PATCH" | "DELETE";
  body?: unknown;
  query?: Query;
  signal?: AbortSignal;
  /** Next.js fetch cache options for server-side calls. */
  next?: { revalidate?: number | false; tags?: string[] };
  cache?: RequestCache;
}

function buildUrl(path: string, query?: Query) {
  const url = new URL(`${API_ORIGIN}${API_PREFIX}${path}`);
  Object.entries(query ?? {}).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== "") {
      url.searchParams.set(key, String(value));
    }
  });
  return url.toString();
}

function readCookie(name: string): string | null {
  if (isServer) return null;
  const match = document.cookie.split("; ").find((row) => row.startsWith(`${name}=`));
  return match ? decodeURIComponent(match.split("=").slice(1).join("=")) : null;
}

/** Ask Laravel for a fresh XSRF-TOKEN cookie (Sanctum SPA flow). */
export async function refreshCsrfCookie(): Promise<void> {
  await fetch(`${API_ORIGIN}/sanctum/csrf-cookie`, { credentials: "include" });
}

const FRIENDLY_MESSAGES: Record<number, string> = {
  401: "Sesi Anda telah berakhir. Silakan masuk kembali.",
  403: "Anda tidak memiliki akses ke halaman ini.",
  404: "Data yang Anda cari tidak ditemukan.",
  409: "Proses yang sama sedang berjalan.",
  419: "Sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.",
  429: "Terlalu banyak percobaan. Tunggu sebentar lalu coba lagi.",
  503: "Layanan sedang tidak tersedia. Coba beberapa saat lagi.",
};

function friendlyMessage(status: number, serverMessage?: string): string {
  // Validation messages from Laravel are already user-facing (and localized).
  if (status === 422 && serverMessage) return serverMessage;
  if (FRIENDLY_MESSAGES[status]) return FRIENDLY_MESSAGES[status];
  // Never show raw server errors (stack traces, SQL...) to users.
  return status >= 500 ? "Terjadi kesalahan pada server. Coba beberapa saat lagi." : "Permintaan tidak dapat diproses.";
}

export async function apiFetch<T>(path: string, options: RequestOptions = {}, retried = false): Promise<T> {
  const method = options.method ?? "GET";
  const headers: Record<string, string> = {
    Accept: "application/json",
    "X-Requested-With": "XMLHttpRequest",
  };

  if (options.body !== undefined) headers["Content-Type"] = "application/json";

  if (!isServer && method !== "GET") {
    if (!readCookie("XSRF-TOKEN")) await refreshCsrfCookie();
    const token = readCookie("XSRF-TOKEN");
    if (token) headers["X-XSRF-TOKEN"] = token;
  }

  let response: Response;
  try {
    response = await fetch(buildUrl(path, options.query), {
      method,
      headers,
      body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
      credentials: "include",
      signal: options.signal,
      cache: options.cache ?? (options.next ? undefined : "no-store"),
      next: options.next,
    });
  } catch (error) {
    if ((error as Error).name === "AbortError") throw error;
    throw new ApiError(0, "Tidak dapat terhubung ke server. Periksa koneksi internet Anda.");
  }

  // CSRF token expired: refresh once and retry.
  if (response.status === 419 && !isServer && !retried) {
    await refreshCsrfCookie();
    return apiFetch<T>(path, options, true);
  }

  if (response.status === 204) return undefined as T;

  const payload = await response.json().catch(() => null);

  if (!response.ok) {
    if (response.status === 401 && !isServer) {
      const scope: AuthScope = path.startsWith("/admin") ? "admin" : "user";
      window.dispatchEvent(new CustomEvent(UNAUTHORIZED_EVENT, { detail: { scope } }));
    }
    throw new ApiError(response.status, friendlyMessage(response.status, payload?.message), payload?.errors ?? {});
  }

  return payload as T;
}

export function errorMessage(error: unknown): string {
  if (error instanceof ApiError) return error.message;
  return "Terjadi kesalahan yang tidak terduga.";
}
