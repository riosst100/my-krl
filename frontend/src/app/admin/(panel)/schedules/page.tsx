"use client";

import { use, useEffect, useState } from "react";
import { ScheduleSyncStatus } from "@/components/admin/ScheduleSyncStatus";
import { DataTable } from "@/components/ui/DataTable";
import {
  Card,
  EmptyState,
  ErrorState,
  LineDot,
  PageHeader,
  Pagination,
  SelectField,
  TableSkeleton,
  TextField,
} from "@/components/ui";
import { getAdminSchedules, getAdminStations } from "@/lib/api/admin";
import { errorMessage } from "@/lib/api/client";
import type { Station } from "@/lib/api/types";
import { lineLabel } from "@/lib/format";
import { useApi } from "@/lib/hooks/useApi";
import { useDebounced } from "@/lib/hooks/useDebounced";

export default function AdminSchedulesPage({
  searchParams,
}: PageProps<"/admin/schedules">) {
  // Deep links from the station detail page: /admin/schedules?station=THB
  const initial = use(searchParams);
  const [stations, setStations] = useState<Station[]>([]);
  const [station, setStation] = useState(
    typeof initial.station === "string" ? initial.station.toUpperCase() : "",
  );
  const [trainNumber, setTrainNumber] = useState("");
  const [page, setPage] = useState(1);
  const train = useDebounced(trainNumber.replace(/[^A-Za-z0-9]/g, ""));

  useEffect(() => {
    getAdminStations({ per_page: 200 })
      .then((res) => setStations(res.data))
      .catch(() => setStations([]));
  }, []);

  const { data, error, loading, reload } = useApi(
    `schedules|${station}|${train}|${page}`,
    () =>
      getAdminSchedules({ station, train_number: train, page, per_page: 50 }),
  );

  const resetPage = () => setPage(1);

  return (
    <>
      <PageHeader
        title="Jadwal"
        description="Data jadwal hasil sinkronisasi yang tersimpan di database."
      />

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
          <EmptyState
            title="Tidak ada jadwal"
            description="Tidak ada jadwal untuk filter ini. Pastikan sinkronisasi sudah berjalan."
          />
        ) : (
          <>
            <DataTable
              busy={loading}
              rows={data.data}
              rowKey={(s) => s.id}
              caption="Daftar jadwal"
              columns={[
                {
                  key: "station",
                  header: "Stasiun",
                  mobile: "title",
                  className: "whitespace-nowrap font-medium text-ink",
                  cell: (s) => (
                    <>
                      {s.station?.name}{" "}
                      <span className="text-xs font-bold text-muted">
                        {s.station?.code}
                      </span>
                    </>
                  ),
                },
                {
                  key: "dep",
                  header: "Berangkat",
                  className: "tabular font-bold",
                  cell: (s) => s.departure_time,
                },
                {
                  key: "train",
                  header: "Kereta",
                  className: "tabular",
                  cell: (s) => s.train_number,
                },
                { key: "dest", header: "Tujuan", cell: (s) => s.destination },
                {
                  key: "arr",
                  header: "Tiba",
                  className: "tabular text-slate-600",
                  cell: (s) => s.destination_arrival_time ?? "—",
                },
                {
                  key: "line",
                  header: "Line / Rute",
                  className: "text-slate-600",
                  cell: (s) => (
                    <>
                      <span className="inline-flex items-center gap-2">
                        <LineDot color={s.color ?? s.line?.color} />
                        {lineLabel(s.line?.name)}
                      </span>
                      {s.route_name && (
                        <span className="block text-xs text-muted">
                          {s.route_name}
                        </span>
                      )}
                    </>
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
