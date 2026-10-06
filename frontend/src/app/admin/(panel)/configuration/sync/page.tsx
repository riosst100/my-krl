"use client";

import { ScheduleSyncPanel } from "@/components/admin/ScheduleSyncPanel";
import { StationSyncPanel } from "@/components/admin/StationSyncPanel";
import { SyncStationsPanel } from "@/components/admin/SyncStationsPanel";
import { PageHeader } from "@/components/ui";

export default function AdminSyncConfigurationPage() {
  return (
    <>
      <PageHeader
        title="Sync Configuration"
        description="Stasiun yang disinkronkan dan alamat API KCI. Berlaku untuk sync manual maupun otomatis. Permintaan ke kci.id lewat layanan kci-fetch (sidik jari TLS browser)."
      />

      <SyncStationsPanel />

      <StationSyncPanel />
      <ScheduleSyncPanel />
    </>
  );
}
