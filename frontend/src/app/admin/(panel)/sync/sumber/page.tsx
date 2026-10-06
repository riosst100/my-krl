"use client";

import { ScheduleSyncPanel } from "@/components/admin/ScheduleSyncPanel";
import { StationSyncPanel } from "@/components/admin/StationSyncPanel";
import { PageHeader } from "@/components/ui";

export default function AdminDataSourcesPage() {
  return (
    <>
      <PageHeader
        title="Sumber Data"
        description="Alamat API KCI untuk mengambil stasiun, jadwal, dan pemberhentian kereta. Permintaan ke kci.id lewat layanan kci-fetch (sidik jari TLS browser)."
      />

      <StationSyncPanel />
      <ScheduleSyncPanel />
    </>
  );
}
