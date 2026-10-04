"use client";

import { useEffect } from "react";
import { ProdPushPanel } from "@/components/admin/ProdPushPanel";
import { SyncStationsPanel } from "@/components/admin/SyncStationsPanel";
import { PageHeader } from "@/components/ui";
import { getSyncLogs } from "@/lib/api/admin";
import { useApi } from "@/lib/hooks/useApi";

export default function AdminSyncPage() {
  const { data, reload } = useApi("sync-status", () => getSyncLogs(1, "push"));
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
        title="Sync ke Prod"
        description="Server tidak bisa mengakses API KCI. Data diambil di komputer lokal lalu dikirim ke server prod dari sini."
      />

      <ProdPushPanel meta={data?.meta} onChanged={reload} />

      {/* Which stations to sync only matters on the local machine, which fetches from KCI. */}
      {data?.meta.mode === "local" && <SyncStationsPanel onChanged={reload} />}
    </>
  );
}
