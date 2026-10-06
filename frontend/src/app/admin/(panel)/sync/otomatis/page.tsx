"use client";

import { AutoSyncPanel } from "@/components/admin/AutoSyncPanel";
import { PageHeader } from "@/components/ui";

export default function AdminAutoSyncPage() {
  return (
    <>
      <PageHeader
        title="Sync Otomatis"
        description="Jam dan jenis sync (stasiun, jadwal, kereta) yang dijalankan sendiri oleh scheduler."
      />

      <AutoSyncPanel />
    </>
  );
}
