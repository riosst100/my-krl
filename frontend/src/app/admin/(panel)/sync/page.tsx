"use client";

import { useEffect } from "react";
import { AutoSyncPanel } from "@/components/admin/AutoSyncPanel";
import { KciSyncPanel } from "@/components/admin/KciSyncPanel";
import { SyncStationsPanel } from "@/components/admin/SyncStationsPanel";
import { PageHeader } from "@/components/ui";
import { getSyncLogs } from "@/lib/api/admin";
import { useApi } from "@/lib/hooks/useApi";

export default function AdminSyncPage() {
  const { data, reload } = useApi("sync-status", () => getSyncLogs(1, "schedules"));
  const inProgress = data?.meta.in_progress ?? false;

  // Poll while a sync is queued/running so the progress bar updates live.
  useEffect(() => {
    if (!inProgress) return;
    const id = setInterval(reload, 2000);
    return () => clearInterval(id);
  }, [inProgress, reload]);

  return (
    <>
      <PageHeader
        title="Sync Data"
        description="Data diambil langsung dari KCI, manual atau otomatis sesuai jadwal."
      />

      <KciSyncPanel meta={data?.meta} onChanged={reload} />

      <AutoSyncPanel />

      <SyncStationsPanel onChanged={reload} />
    </>
  );
}
