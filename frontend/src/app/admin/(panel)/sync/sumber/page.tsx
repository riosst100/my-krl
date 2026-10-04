"use client";

import { ScheduleSyncPanel } from "@/components/admin/ScheduleSyncPanel";
import { StationSyncPanel } from "@/components/admin/StationSyncPanel";
import { Alert, PageHeader, Skeleton } from "@/components/ui";
import { getSyncLogs } from "@/lib/api/admin";
import { useApi } from "@/lib/hooks/useApi";

export default function AdminDataSourcesPage() {
  const { data } = useApi("sync-mode", () => getSyncLogs(1, "push"));

  return (
    <>
      <PageHeader
        title="Sumber Data"
        description="Alamat API KCI yang dipakai komputer lokal untuk mengambil stasiun, jadwal, dan pemberhentian kereta."
      />

      {!data ? (
        <Skeleton className="h-32 w-full" />
      ) : data.meta.mode === "local" ? (
        <>
          <StationSyncPanel />
          <ScheduleSyncPanel />
        </>
      ) : (
        <Alert tone="info">
          Server ini tidak mengambil data dari KCI, jadi sumber data hanya
          diatur di panel admin komputer lokal.
        </Alert>
      )}
    </>
  );
}
