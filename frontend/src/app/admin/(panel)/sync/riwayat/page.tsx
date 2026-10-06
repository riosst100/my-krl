"use client";

import { useState } from "react";
import {
  SYNC_TYPE_LABELS,
  SyncStatusBadge,
  TRIGGER_LABELS,
} from "@/components/admin/SyncStatusBadge";
import { DataTable } from "@/components/ui/DataTable";
import {
  Card,
  EmptyState,
  ErrorState,
  PageHeader,
  Pagination,
  SelectField,
  TableSkeleton,
} from "@/components/ui";
import { getSyncLogs, type KciSyncType } from "@/lib/api/admin";
import { errorMessage } from "@/lib/api/client";
import { formatDateTime, formatNumber } from "@/lib/format";
import { useApi } from "@/lib/hooks/useApi";

export default function AdminSyncHistoryPage() {
  const [page, setPage] = useState(1);
  const [type, setType] = useState<"" | KciSyncType>("");
  const { data, error, loading, reload } = useApi(`sync|${page}|${type}`, () =>
    getSyncLogs(page, type),
  );

  return (
    <>
      <PageHeader
        title="Riwayat Sinkronisasi"
        description="Semua sync dari KCI (manual dan otomatis) dan import manual."
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
            <option value="trains">Kereta</option>
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
            <DataTable
              rows={data.data}
              rowKey={(log) => log.id}
              caption="Riwayat sinkronisasi"
              columns={[
                {
                  key: "type",
                  header: "Jenis",
                  mobile: "title",
                  className: "font-medium text-ink",
                  cell: (log) => (
                    <span className="flex flex-wrap items-center gap-2">
                      {SYNC_TYPE_LABELS[log.type] ?? log.type}
                      <span className="tabular text-xs font-normal text-muted">
                        #{log.id}
                      </span>
                    </span>
                  ),
                },
                {
                  key: "id",
                  header: "#",
                  mobile: "hide",
                  className: "tabular text-muted",
                  cell: (log) => log.id,
                },
                {
                  key: "status",
                  header: "Status",
                  cell: (log) => <SyncStatusBadge status={log.status} />,
                },
                {
                  key: "trigger",
                  header: "Pemicu",
                  cell: (log) => (
                    <>
                      {TRIGGER_LABELS[log.trigger] ?? log.trigger}
                      {log.source && (
                        <span className="block text-xs text-muted">
                          sumber: {log.source}
                        </span>
                      )}
                    </>
                  ),
                },
                {
                  key: "start",
                  header: "Mulai",
                  className: "whitespace-nowrap text-slate-600",
                  cell: (log) =>
                    formatDateTime(log.started_at ?? log.created_at),
                },
                {
                  key: "dur",
                  header: "Durasi",
                  className: "tabular text-slate-600",
                  cell: (log) =>
                    log.duration_seconds !== null
                      ? `${log.duration_seconds} dtk`
                      : "—",
                },
                {
                  key: "data",
                  header: "Data",
                  className: "tabular",
                  cell: (log) => formatNumber(log.records_processed),
                },
                {
                  key: "msg",
                  header: "Pesan",
                  className: "max-w-xs text-xs text-red-700",
                  cell: (log) =>
                    log.error_message ? (
                      <span
                        className="line-clamp-2 break-words"
                        title={log.error_message}
                      >
                        {log.error_message}
                      </span>
                    ) : null,
                },
              ]}
            />
            <Pagination
              page={data.meta.current_page}
              lastPage={data.meta.last_page}
              total={data.meta.total}
              onChange={setPage}
            />
          </>
        )}
      </Card>
    </>
  );
}
