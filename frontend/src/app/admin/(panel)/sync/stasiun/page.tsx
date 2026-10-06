"use client";

import { KciSyncPage } from "@/components/admin/KciSyncPage";

export default function AdminSyncStationsPage() {
  return (
    <KciSyncPage
      type="stations"
      title="Sync Stasiun"
      description="Daftar stasiun (nama, wilayah, status KCI) diambil dari KCI. Jarang berubah, cukup dijalankan sesekali."
      panelTitle="Sync stasiun dari KCI"
      panelDescription="Menambah stasiun baru dan memperbarui nama/wilayah. Status aktif yang diatur admin tidak diubah."
    />
  );
}
