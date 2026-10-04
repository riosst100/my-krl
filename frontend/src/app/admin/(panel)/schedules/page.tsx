"use client";

import { use, useEffect, useState } from "react";
import { ScheduleSyncStatus } from "@/components/admin/ScheduleSyncStatus";
import { Card, EmptyState, ErrorState, LineDot, PageHeader, Pagination, SelectField, TableSkeleton, TextField } from "@/components/ui";
import { getAdminSchedules, getAdminStations } from "@/lib/api/admin";
import { errorMessage } from "@/lib/api/client";
import type { Station } from "@/lib/api/types";
import { lineLabel } from "@/lib/format";
import { useApi } from "@/lib/hooks/useApi";
import { useDebounced } from "@/lib/hooks/useDebounced";

export default function AdminSchedulesPage({ searchParams }: PageProps<"/admin/schedules">) {
  // Deep links from the station detail page: /admin/schedules?station=THB
  const initial = use(searchParams);
  const [stations, setStations] = useState<Station[]>([]);
  const [station, setStation] = useState(typeof initial.station === "string" ? initial.station.toUpperCase() : "");
  const [trainNumber, setTrainNumber] = useState("");
  const [page, setPage] = useState(1);
  const train = useDebounced(trainNumber.replace(/[^A-Za-z0-9]/g, ""));

  useEffect(() => {
    getAdminStations({ per_page: 200 })
      .then((res) => setStations(res.data))
      .catch(() => setStations([]));
  }, []);

  const { data, error, loading, reload } = useApi(`schedules|${station}|${train}|${page}`, () =>
    getAdminSchedules({ station, train_number: train, page, per_page: 50 }),
  );

  const resetPage = () => setPage(1);

  return (
    <>
      <PageHeader title="Jadwal" description="Data jadwal hasil sinkronisasi yang tersimpan di database." />

      <ScheduleSyncStatus onSynced={reload} />

      <div className="mb-4 grid gap-4 sm:grid-cols-3">
        <SelectField
          label="Stasiun"
          value={station}
          onChange={(e) => {
            setStation(e.target.value);
            resetPage();
          }}
        >
          <option value="">Semua stasiun</option>
          {stations.map((s) => (
            <option key={s.id} value={s.code}>
              {s.name} ({s.code}){s.is_active ? "" : " — nonaktif"}
            </option>
          ))}
        </SelectField>
        <TextField
          label="Nomor kereta"
          type="search"
          inputMode="numeric"
          placeholder="mis. 5012"
          value={trainNumber}
          onChange={(e) => {
            setTrainNumber(e.target.value);
            resetPage();
          }}
        />
      </div>

      <Card className="overflow-hidden">
        {error ? (
          <ErrorState message={errorMessage(error)} onRetry={reload} />
        ) : loading && !data ? (
          <TableSkeleton rows={10} />
        ) : !data || data.data.length === 0 ? (
          <EmptyState title="Tidak ada jadwal" description="Tidak ada jadwal untuk filter ini. Pastikan sinkronisasi sudah berjalan." />
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm">
                <thead className="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-muted">
                  <tr>
                    <th scope="col" className="px-4 py-3">Stasiun</th>
                    <th scope="col" className="px-4 py-3">Berangkat</th>
                    <th scope="col" className="px-4 py-3">Kereta</th>
                    <th scope="col" className="px-4 py-3">Tujuan</th>
                    <th scope="col" className="px-4 py-3">Tiba</th>
                    <th scope="col" className="px-4 py-3">Line / Rute</th>
                  </tr>
                </thead>
                <tbody className={`divide-y divide-line ${loading ? "opacity-60" : ""}`}>
                  {data.data.map((s) => (
                    <tr key={s.id}>
                      <td className="whitespace-nowrap px-4 py-2.5 font-medium text-ink">
                        {s.station?.name} <span className="text-xs text-muted">{s.station?.code}</span>
                      </td>
                      <td className="tabular px-4 py-2.5 font-bold">{s.departure_time}</td>
                      <td className="tabular px-4 py-2.5">{s.train_number}</td>
                      <td className="px-4 py-2.5">{s.destination}</td>
                      <td className="tabular px-4 py-2.5 text-slate-600">{s.destination_arrival_time ?? "—"}</td>
                      <td className="px-4 py-2.5 text-slate-600">
                        <span className="inline-flex items-center gap-2">
                          <LineDot color={s.color ?? s.line?.color} />
                          {lineLabel(s.line?.name)}
                        </span>
                        <span className="block text-xs text-muted">{s.route_name}</span>
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
