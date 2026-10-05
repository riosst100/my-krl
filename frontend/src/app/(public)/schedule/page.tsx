import type { Metadata } from "next";
import Link from "next/link";
import { Alert, Card, EmptyState, PageHeader } from "@/components/ui";
import { ScheduleBoard } from "@/components/schedule/ScheduleBoard";
import { TripPicker } from "@/components/schedule/TripPicker";
import { StationSearchForm } from "@/components/schedule/StationSearchForm";
import { getStations } from "@/lib/api/stations";
import type { Station } from "@/lib/api/types";

export const metadata: Metadata = { title: "Cari Jadwal" };

export default async function SchedulePage(props: PageProps<"/schedule">) {
  const params = await props.searchParams;
  const code = typeof params.station === "string" ? params.station.toUpperCase() : "";
  const to = typeof params.to === "string" && /^[A-Za-z0-9-]{1,120}$/.test(params.to) ? params.to.toUpperCase() : undefined;

  let stations: Station[] = [];
  let failed = false;
  try {
    stations = await getStations();
  } catch {
    failed = true;
  }

  const station = stations.find((s) => s.code === code);

  return (
    <>
      <PageHeader title="Cari Jadwal" description="Pilih stasiun keberangkatan. Jadwal yang tampil selalu yang terbaru." />

      <Card className="mb-5 p-4 sm:mb-6 sm:p-6">
        {failed ? (
          <Alert>Daftar stasiun tidak dapat dimuat. Coba muat ulang halaman.</Alert>
        ) : (
          <StationSearchForm stations={stations} defaultStation={station?.code} layout="inline" />
        )}
      </Card>

      {station ? (
        <>
          <div className="mb-3 flex items-end justify-between gap-4 sm:mb-4">
            <div className="min-w-0">
              <p className="text-xs font-medium text-muted sm:text-sm">Stasiun {station.code}</p>
              <h2 className="truncate text-lg font-bold text-ink sm:text-2xl">Stasiun {station.name}</h2>
            </div>
            <Link
              href={`/stations/${station.code}${to ? `?to=${to}` : ""}`}
              className="shrink-0 text-[13px] font-semibold text-brand-600 hover:underline sm:text-sm"
            >
              Halaman stasiun →
            </Link>
          </div>
          <div className="mb-4 card border border-line/80 bg-white p-4 shadow-card sm:p-5">
            <TripPicker stationCode={station.code} stationName={station.name} to={to} keep={{ station: station.code }} />
          </div>
          <ScheduleBoard key={`${station.code}-${to ?? ""}`} stationCode={station.code} to={to} />
        </>
      ) : code && !failed ? (
        <Card>
          <EmptyState title="Stasiun tidak ditemukan" description={`Kode stasiun "${code}" tidak dikenal atau sedang tidak aktif.`} />
        </Card>
      ) : !failed ? (
        <Card>
          <EmptyState title="Pilih stasiun untuk melihat jadwal" description="Jadwal keberangkatan akan tampil di sini." />
        </Card>
      ) : null}
    </>
  );
}
