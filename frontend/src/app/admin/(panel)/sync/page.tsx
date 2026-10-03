"use client";

import { useEffect, useState } from "react";
import { ScheduleSyncPanel } from "@/components/admin/ScheduleSyncPanel";
import { SYNC_TYPE_LABELS, SyncStatusBadge, TRIGGER_LABELS } from "@/components/admin/SyncStatusBadge";
import { Card, EmptyState, ErrorState, PageHeader, Pagination, SelectField, TableSkeleton } from "@/components/ui";
import { getSyncLogs } from "@/lib/api/admin";
import { errorMessage } from "@/lib/api/client";
import { formatDateTime, formatNumber } from "@/lib/format";
import { useApi } from "@/lib/hooks/useApi";

export default function AdminSyncPage() {
  const [page, setPage] = useState(1);
  const [type, setType] = useState<"" | "schedules" | "stations">("");
  const { data, error, loading, reload } = useApi(`sync|${page}|${type}`, () => getSyncLogs(page, type));

  const inProgress = data?.meta.in_progress ?? false;

  // Poll while a sync is queued/running so the status updates live.
  useEffect(() => {
    if (!inProgress) return;
    const id = setInterval(reload, 3000);
    return () => clearInterval(id);
  }, [inProgress, reload]);

  return (
    <>
      <PageHeader
        title="Sinkronisasi KCI"
        description="Jadwal disinkronkan otomatis setiap hari, data stasiun setiap bulan (kelola di menu Stasiun)."
      />

      <ScheduleSyncPanel
        meta={data?.meta}
        onChanged={() => {
          setPage(1);
          reload();
        }}
      />

      <Card className="overflow-hidden">
        <div className="flex flex-wrap items-end justify-between gap-3 border-b border-line px-4 py-3">
          <h2 className="font-semibold text-ink">Riwayat</h2>
          <SelectField
            label="Jenis"
            value={type}
            className="w-44"
            onChange={(e) => {
              setType(e.target.value as typeof type);
              setPage(1);
            }}
          >
            <option value="">Semua</option>
            <option value="schedules">Jadwal</option>
            <option value="stations">Stasiun</option>
          </SelectField>
        </div>
        {error ? (
          <ErrorState message={errorMessage(error)} onRetry={reload} />
        ) : loading && !data ? (
          <TableSkeleton />
        ) : !data || data.data.length === 0 ? (
          <EmptyState title="Belum ada riwayat sinkronisasi" />
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm">
                <thead className="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-muted">
                  <tr>
                    <th scope="col" className="px-4 py-3">#</th>
                    <th scope="col" className="px-4 py-3">Jenis</th>
                    <th scope="col" className="px-4 py-3">Status</th>
                    <th scope="col" className="px-4 py-3">Pemicu</th>
                    <th scope="col" className="px-4 py-3">Mulai</th>
                    <th scope="col" className="px-4 py-3">Durasi</th>
                    <th scope="col" className="px-4 py-3">Data</th>
                    <th scope="col" className="px-4 py-3">Pesan</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-line">
                  {data.data.map((log) => (
                    <tr key={log.id} className="align-top">
                      <td className="tabular px-4 py-3 text-muted">{log.id}</td>
                      <td className="px-4 py-3 font-medium text-ink">{SYNC_TYPE_LABELS[log.type] ?? log.type}</td>
                      <td className="px-4 py-3">
                        <SyncStatusBadge status={log.status} />
                      </td>
                      <td className="px-4 py-3">
                        {TRIGGER_LABELS[log.trigger] ?? log.trigger}
                        {log.source && <span className="block text-xs text-muted">sumber: {log.source}</span>}
                      </td>
                      <td className="whitespace-nowrap px-4 py-3 text-slate-600">{formatDateTime(log.started_at ?? log.created_at)}</td>
                      <td className="tabular px-4 py-3 text-slate-600">{log.duration_seconds !== null ? `${log.duration_seconds} dtk` : "—"}</td>
                      <td className="tabular px-4 py-3">{formatNumber(log.records_processed)}</td>
                      <td className="max-w-xs px-4 py-3 text-xs text-red-700">
                        <span className="line-clamp-2" title={log.error_message ?? undefined}>
                          {log.error_message ?? ""}
                        </span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <Pagination page={data.meta.current_page} lastPage={data.meta.last_page} total={data.meta.total} onChange={setPage} />
          </>
        )}
      </Card>
    </>
  );
}
