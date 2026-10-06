"use client";

import { useEffect, type ReactNode } from "react";
import { KciSyncPanel } from "@/components/admin/KciSyncPanel";
import { PageHeader } from "@/components/ui";
import { getSyncLogs, type KciSyncType } from "@/lib/api/admin";
import { useApi } from "@/lib/hooks/useApi";

/**
 * Admin → Sinkronisasi → Sync Stasiun / Jadwal / Kereta: the manual sync of one
 * kind with live progress and its own settings (children). The automatic
 * sync has its own page (Sync Otomatis).
 */
export function KciSyncPage({
  type,
  title,
  description,
  panelTitle,
  panelDescription,
  children,
}: {
  type: KciSyncType;
  title: string;
  description: string;
  panelTitle: string;
  panelDescription: string;
  /** Settings of this kind, shown below the sync panel. */
  children?: (reload: () => void) => ReactNode;
}) {
  const { data, reload } = useApi(`sync-status|${type}`, () =>
    getSyncLogs(1, type),
  );
  const inProgress = data?.meta.in_progress ?? false;

  // Poll while a sync is queued/running so the progress bar updates live.
  useEffect(() => {
    if (!inProgress) return;
    const id = setInterval(reload, 2000);
    return () => clearInterval(id);
  }, [inProgress, reload]);

  return (
    <>
      <PageHeader title={title} description={description} />

      <KciSyncPanel
        type={type}
        title={panelTitle}
        description={panelDescription}
        meta={data?.meta}
        onChanged={reload}
      />

      {children?.(reload)}
    </>
  );
}
