"use client";

import { ManualImportPanel } from "@/components/admin/ManualImportPanel";
import { PageHeader } from "@/components/ui";
import { getAdminStations } from "@/lib/api/admin";
import { useApi } from "@/lib/hooks/useApi";

export default function AdminManualImportPage() {
  const stations = useApi(
    "import-stations",
    async () =>
      (await getAdminStations({ status: "active", per_page: 200 })).data,
  );

  return (
    <>
      <PageHeader
        title="Import Manual"
        description="Tempel JSON dari response API KCI kalau API tidak bisa diakses dari server ini."
      />

      <ManualImportPanel
        stations={stations.data ?? []}
        onImported={() => undefined}
      />
    </>
  );
}
