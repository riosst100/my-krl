"use client";

import { useEffect, useRef, useState } from "react";
import { getSyncRequests, type SyncRequestRow, type SyncRequestsResponse } from "@/lib/api/admin";
import { formatNumber } from "@/lib/format";

/** Rows kept in the browser; a full sync sends ~600 requests. */
const MAX_ROWS = 2000;

const timeFormat = new Intl.DateTimeFormat("id-ID", {
  hour: "2-digit",
  minute: "2-digit",
  second: "2-digit",
  hour12: false,
});

/**
 * "Log request" of one sync run: every URL sent to KCI and whether it returned
 * data (no response body). Polls while the run is in progress. Render it with
 * key={logId} so another run starts with an empty log.
 */
export function SyncRequestLog({ logId, running }: { logId: number; running: boolean }) {
  const [rows, setRows] = useState<SyncRequestRow[]>([]);
  const [counts, setCounts] = useState<SyncRequestsResponse["meta"] | null>(null);
  const lastId = useRef(0);
  const box = useRef<HTMLDivElement>(null);
  const stick = useRef(true);

  useEffect(() => {
    let cancelled = false;

    const load = async () => {
      try {
        const res = await getSyncRequests(logId, lastId.current);
        if (cancelled) return;
        if (res.data.length > 0) {
          lastId.current = res.data[res.data.length - 1].id;
          setRows((prev) => [...prev, ...res.data].slice(-MAX_ROWS));
        }
        setCounts(res.meta);
      } catch {
        // Informational only: the next poll tries again.
      }
    };

    load();
    if (!running) return () => void (cancelled = true);

    const id = setInterval(load, 2000);
    return () => {
      cancelled = true;
      clearInterval(id);
    };
  }, [logId, running]);

  // Follow new lines unless the admin scrolled up to read.
  useEffect(() => {
    const el = box.current;
    if (el && stick.current) el.scrollTop = el.scrollHeight;
  }, [rows]);

  if (!counts || counts.total === 0) {
    return running ? <p className="mt-3 text-xs text-muted">Log request: menunggu request pertama…</p> : null;
  }

  return (
    <details open={running || counts.failed > 0} className="mt-4 rounded-xl border border-line">
      <summary className="cursor-pointer select-none px-3.5 py-2.5 text-sm font-medium text-ink">
        Log request
        <span className="font-normal text-muted">
          {" "}
          · {formatNumber(counts.total)} request
          {" · "}
          <span className="text-emerald-700">{formatNumber(counts.ok)} berhasil</span>
          {counts.failed > 0 && (
            <>
              {" · "}
              <span className="text-red-700">{formatNumber(counts.failed)} gagal</span>
            </>
          )}
        </span>
      </summary>
      <div
        ref={box}
        onScroll={(e) => {
          const el = e.currentTarget;
          stick.current = el.scrollHeight - el.scrollTop - el.clientHeight < 24;
        }}
        className="max-h-80 overflow-y-auto border-t border-line bg-slate-50/60 px-3.5 py-2 font-mono text-xs leading-relaxed"
        role="log"
        aria-live={running ? "polite" : "off"}
      >
        {counts.total > rows.length && (
          <p className="text-muted">… {formatNumber(counts.total - rows.length)} request sebelumnya tidak ditampilkan</p>
        )}
        {rows.map((row) => (
          <RequestLine key={row.id} row={row} />
        ))}
      </div>
    </details>
  );
}

function RequestLine({ row }: { row: SyncRequestRow }) {
  const detail = [
    row.status_code ? `HTTP ${row.status_code}` : null,
    row.duration_ms !== null ? `${(row.duration_ms / 1000).toFixed(1)} s` : null,
  ].filter(Boolean);

  return (
    <div className="flex gap-2 py-0.5">
      <time className="shrink-0 text-muted" dateTime={row.created_at}>
        {timeFormat.format(new Date(row.created_at))}
      </time>
      <span aria-hidden className={row.ok ? "text-emerald-600" : "text-red-600"}>
        {row.ok ? "✓" : "✗"}
      </span>
      <p className="min-w-0 break-all">
        <span className="text-slate-500">GET</span> <span className="text-ink">{row.url}</span>
        <span className={row.ok ? "text-emerald-700" : "text-red-700"}>
          {" → "}
          {row.message ?? (row.ok ? "OK" : "Gagal")}
        </span>
        {detail.length > 0 && <span className="text-muted"> ({detail.join(", ")})</span>}
        <span className="sr-only">{row.ok ? " berhasil" : " gagal"}</span>
      </p>
    </div>
  );
}
