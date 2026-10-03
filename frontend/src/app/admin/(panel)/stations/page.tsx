"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { StationSyncPanel } from "@/components/admin/StationSyncPanel";
import { Badge, Button, Card, EmptyState, ErrorState, PageHeader, Pagination, SelectField, TableSkeleton, TextField } from "@/components/ui";
import { useToast } from "@/components/ui/Toast";
import { getAdminStations, setStationActive } from "@/lib/api/admin";
import { errorMessage } from "@/lib/api/client";
import type { Station } from "@/lib/api/types";
import { formatNumber } from "@/lib/format";
import { useApi } from "@/lib/hooks/useApi";
import { useDebounced } from "@/lib/hooks/useDebounced";

export default function AdminStationsPage() {
  const { toast } = useToast();
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState<"" | "active" | "inactive">("");
  const [page, setPage] = useState(1);
  const [overrides, setOverrides] = useState<Record<number, boolean>>({});
  const [busy, setBusy] = useState<number | null>(null);
  const q = useDebounced(search);

  const { data, error, loading, reload } = useApi(`stations|${q}|${status}|${page}`, () => getAdminStations({ search: q, status, page }));

  const meta = data?.meta;
  const inProgress = meta?.station_sync_in_progress ?? false;

  // Refresh while a station sync is queued/running.
  useEffect(() => {
    if (!inProgress) return;
    const id = setInterval(reload, 3000);
    return () => clearInterval(id);
  }, [inProgress, reload]);

  const toggle = async (station: Station, active: boolean) => {
    setBusy(station.id);
    try {
      const updated = await setStationActive(station.id, !active);
      setOverrides((o) => ({ ...o, [station.id]: updated.is_active }));
      toast(`Stasiun ${updated.name} ${updated.is_active ? "diaktifkan" : "dinonaktifkan"}.`, "success");
    } catch (err) {
      toast(errorMessage(err), "error");
    } finally {
      setBusy(null);
    }
  };

  return (
    <>
      <PageHeader title="Stasiun" description="Data master stasiun. Stasiun nonaktif tidak tampil di situs publik dan tidak disinkronkan jadwalnya." />

      <StationSyncPanel meta={meta} onChanged={reload} />

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
            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm">
                <thead className="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-muted">
                  <tr>
                    <th scope="col" className="px-4 py-3">Kode</th>
                    <th scope="col" className="px-4 py-3">Nama</th>
                    <th scope="col" className="px-4 py-3">Wilayah</th>
                    <th scope="col" className="px-4 py-3">Jadwal hari ini</th>
                    <th scope="col" className="px-4 py-3">Status KCI</th>
                    <th scope="col" className="px-4 py-3">Tampil di situs</th>
                    <th scope="col" className="px-4 py-3"><span className="sr-only">Aksi</span></th>
                  </tr>
                </thead>
                <tbody className={`divide-y divide-line ${loading ? "opacity-60" : ""}`}>
                  {data.data.map((s) => {
                    const active = overrides[s.id] ?? s.is_active;
                    return (
                      <tr key={s.id}>
                        <td className="px-4 py-3 font-bold text-ink">{s.code}</td>
                        <td className="px-4 py-3 font-medium">
                          <Link href={`/admin/stations/${s.id}`} className="text-ink hover:text-brand-700 hover:underline">
                            {s.name}
                          </Link>
                        </td>
                        <td className="px-4 py-3 text-slate-600">{s.operational_area_name ?? (s.operational_area ?? "—")}</td>
                        <td className="tabular px-4 py-3 text-slate-600">{formatNumber(s.schedules_count ?? 0)}</td>
                        <td className="px-4 py-3">
                          <Badge tone={s.kci_enabled ? "neutral" : "warning"}>{s.kci_enabled ? "Beroperasi" : "Tidak beroperasi"}</Badge>
                        </td>
                        <td className="px-4 py-3">
                          <Badge tone={active ? "success" : "neutral"}>{active ? "Aktif" : "Nonaktif"}</Badge>
                        </td>
                        <td className="whitespace-nowrap px-4 py-3 text-right">
                          <Link href={`/admin/stations/${s.id}`} className="mr-3 text-sm font-semibold text-brand-600 hover:underline">
                            Detail
                          </Link>
                          <Button
                            size="sm"
                            variant={active ? "danger" : "secondary"}
                            loading={busy === s.id}
                            onClick={() => toggle(s, active)}
                            aria-label={`${active ? "Nonaktifkan" : "Aktifkan"} stasiun ${s.name}`}
                          >
                            {active ? "Nonaktifkan" : "Aktifkan"}
                          </Button>
                        </td>
                      </tr>
                    );
                  })}
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
