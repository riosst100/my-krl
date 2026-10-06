"use client";

import { KciSyncPage } from "@/components/admin/KciSyncPage";
import { SelectedSyncStations } from "@/components/admin/SelectedSyncStations";

export default function AdminSyncTrainsPage() {
  return (
    <KciSyncPage
      type="trains"
      title="Sync Kereta"
      description="Pemberhentian tiap kereta diambil dari KCI, untuk pencarian “ke stasiun”."
      panelTitle="Sync kereta dari KCI"
      panelDescription="Mengambil pemberhentian setiap kereta yang ada di jadwal stasiun terpilih. Pemberhentian kereta lain dihapus. Jalankan Sync Jadwal lebih dulu."
    >
      {() => <SelectedSyncStations subject="Kereta dari jadwal" />}
    </KciSyncPage>
  );
}
