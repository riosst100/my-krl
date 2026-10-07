"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import {
  Badge,
  Card,
  EmptyState,
  ErrorState,
  PageHeader,
  Pagination,
  SelectField,
  TableSkeleton,
  TextField,
} from "@/components/ui";
import { DataTable } from "@/components/ui/DataTable";
import { getAdminStations } from "@/lib/api/admin";
import { errorMessage } from "@/lib/api/client";
import { formatDateTime, formatNumber } from "@/lib/format";
import { useApi } from "@/lib/hooks/useApi";
import { useDebounced } from "@/lib/hooks/useDebounced";

export default function AdminStationsPage() {
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState<"" | "active" | "inactive">("");
  const [page, setPage] = useState(1);
  const q = useDebounced(search);

  const { data, error, loading, reload } = useApi(
    `stations|${q}|${status}|${page}`,
    () => getAdminStations({ search: q, status, page }),
  );

  const meta = data?.meta;
  const inProgress = meta?.station_sync_in_progress ?? false;

  // Refresh while a station sync is queued/running.
  useEffect(() => {
    if (!inProgress) return;
    const id = setInterval(reload, 3000);
    return () => clearInterval(id);
  }, [inProgress, reload]);

  return (
    <>
      <PageHeader
        title="Stasiun"
        description="Data master stasiun, urut dari jadwal hari ini terbanyak lalu yang terakhir disinkronkan. Stasiun abu-abu belum pernah disinkronkan. Stasiun nonaktif tidak tampil di situs publik."
      />

      <div className="mb-4 grid gap-4 sm:grid-cols-[1fr_200px]">
        <TextField
          label="Cari"
          type="search"
          placeholder="Nama atau kode stasiun"
          value={search}
          onChange={(e) => {
            setSearch(e.target.value);
            setPage(1);
          }}
        />
        <SelectField
          label="Status"
          value={status}
          onChange={(e) => {
            setStatus(e.target.value as typeof status);
            setPage(1);
          }}
        >
          <option value="">Semua</option>
          <option value="active">Aktif</option>
          <option value="inactive">Nonaktif</option>
        </SelectField>
      </div>

      <Card className="overflow-hidden">
        {error ? (
          <ErrorState message={errorMessage(error)} onRetry={reload} />
        ) : loading && !data ? (
          <TableSkeleton />
        ) : !data || data.data.length === 0 ? (
          <EmptyState title="Tidak ada stasiun" />
        ) : (
          <>
            <DataTable
              busy={loading}
              rows={data.data}
              rowKey={(s) => s.id}
              caption="Daftar stasiun"
              rowClassName={(s) =>
                s.schedules_synced_at ? undefined : "bg-slate-50 opacity-50"
              }
              columns={[
                {
                  key: "name",
                  header: "Nama",
                  mobile: "title",
                  className: "font-medium",
                  cell: (s) => (
                    <Link
                      href={`/admin/stations/${s.id}`}
                      className="text-ink hover:text-brand-700 hover:underline"
                    >
                      {s.name}{" "}
                      <span className="text-xs font-bold text-muted">
                        {s.code}
                      </span>
                    </Link>
                  ),
                },
                {
                  key: "code",
                  header: "Kode",
                  mobile: "hide",
                  className: "font-bold text-ink",
                  cell: (s) => s.code,
                },
                {
                  key: "area",
                  header: "Wilayah",
                  className: "text-slate-600",
                  cell: (s) =>
                    s.operational_area_name ?? s.operational_area ?? "—",
                },
                {
                  key: "today",
                  header: "Jadwal hari ini",
                  className: "tabular text-slate-600",
                  cell: (s) => formatNumber(s.schedules_count ?? 0),
                },
                {
                  key: "synced",
                  header: "Terakhir sync",
                  className: "whitespace-nowrap tabular text-slate-600",
                  cell: (s) =>
                    s.schedules_synced_at ? (
                      <time dateTime={s.schedules_synced_at}>
                        {formatDateTime(s.schedules_synced_at)}
                      </time>
                    ) : (
                      "Belum pernah"
                    ),
                },
                {
                  key: "kci",
                  header: "Status KCI",
                  cell: (s) => (
                    <Badge tone={s.kci_enabled ? "neutral" : "warning"}>
                      {s.kci_enabled ? "Beroperasi" : "Tidak beroperasi"}
                    </Badge>
                  ),
                },
                {
                  key: "shown",
                  header: "Tampil di situs",
                  cell: (s) => (
                    <Badge tone={s.is_active ? "success" : "neutral"}>
                      {s.is_active ? "Aktif" : "Nonaktif"}
                    </Badge>
                  ),
                },
                {
                  key: "actions",
                  header: "Aksi",
                  mobile: "action",
                  className: "whitespace-nowrap text-right",
                  cell: (s) => (
                    <Link
                      href={`/admin/stations/${s.id}`}
                      className="text-sm font-semibold text-brand-600 hover:underline"
                    >
                      Detail
                    </Link>
                  ),
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
