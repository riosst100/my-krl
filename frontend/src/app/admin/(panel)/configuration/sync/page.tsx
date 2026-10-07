"use client";

import { KrlSyncAutoPanel } from "@/components/admin/KrlSyncAutoPanel";
import { SyncStationsPanel } from "@/components/admin/SyncStationsPanel";
import { PageHeader } from "@/components/ui";

export default function AdminSyncConfigurationPage() {
  return (
    <>
      <PageHeader
        title="Sync Configuration"
        description="Data jadwal dikirim oleh krl-sync di Vercel (server ini tidak bisa mengakses KCI). Atur stasiun yang disinkronkan dan sync otomatisnya di sini."
      />

      <KrlSyncAutoPanel />
      <SyncStationsPanel />
    </>
  );
}
