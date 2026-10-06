"use client";

import { KciSyncPage } from "@/components/admin/KciSyncPage";
import { SelectedSyncStations } from "@/components/admin/SelectedSyncStations";

export default function AdminSyncSchedulesPage() {
  return (
    <KciSyncPage
      type="schedules"
      title="Sync Jadwal"
      description="Jadwal keberangkatan diambil dari KCI untuk stasiun yang dipilih."
      panelTitle="Sync jadwal dari KCI"
      panelDescription="Mengambil jadwal keberangkatan stasiun terpilih. Setelah itu jalankan Sync Kereta untuk pemberhentiannya."
    >
      {() => <SelectedSyncStations subject="Jadwal diambil untuk" />}
    </KciSyncPage>
  );
}
