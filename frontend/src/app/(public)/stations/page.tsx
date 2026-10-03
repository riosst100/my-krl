import type { Metadata } from "next";
import { Alert, PageHeader } from "@/components/ui";
import { StationDirectory } from "@/components/schedule/StationDirectory";
import { getStations } from "@/lib/api/stations";
import type { Station } from "@/lib/api/types";

export const metadata: Metadata = { title: "Daftar Stasiun" };

export default async function StationsPage() {
  let stations: Station[] | null = null;
  try {
    stations = await getStations();
  } catch {
    stations = null;
  }

  if (!stations) {
    return (
      <>
        <PageHeader title="Daftar Stasiun" />
        <Alert>Daftar stasiun tidak dapat dimuat saat ini. Silakan coba beberapa saat lagi.</Alert>
      </>
    );
  }

  return (
    <>
      <PageHeader title="Daftar Stasiun" description={`${stations.length} stasiun KRL Commuter Line aktif.`} />
      <StationDirectory stations={stations} />
    </>
  );
}
