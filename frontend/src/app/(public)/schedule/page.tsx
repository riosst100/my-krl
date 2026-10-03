import type { Metadata } from "next";
import Link from "next/link";
import { Alert, Card, EmptyState, PageHeader } from "@/components/ui";
import { ScheduleBoard } from "@/components/schedule/ScheduleBoard";
import { TripPicker } from "@/components/schedule/TripPicker";
import { StationSearchForm } from "@/components/schedule/StationSearchForm";
import { getStations } from "@/lib/api/stations";
import type { Station } from "@/lib/api/types";
import { isValidDate, todayInJakarta } from "@/lib/format";

export const metadata: Metadata = { title: "Cari Jadwal" };

export default async function SchedulePage(props: PageProps<"/schedule">) {
  const params = await props.searchParams;
  const code = typeof params.station === "string" ? params.station.toUpperCase() : "";
  const date = typeof params.date === "string" && isValidDate(params.date) ? params.date : todayInJakarta();
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
      <PageHeader title="Cari Jadwal" description="Pilih stasiun keberangkatan dan tanggal perjalanan." />

      <Card className="mb-6 p-4 sm:p-6">
        {failed ? (
          <Alert>Daftar stasiun tidak dapat dimuat. Coba muat ulang halaman.</Alert>
        ) : (
          <StationSearchForm stations={stations} defaultStation={station?.code} defaultDate={date} layout="inline" />
        )}
      </Card>

      {station ? (
        <>
          <div className="mb-4 flex items-end justify-between gap-4">
            <div>
              <p className="text-sm text-muted">Stasiun {station.code}</p>
              <h2 className="text-2xl font-bold text-ink">Stasiun {station.name}</h2>
            </div>
            <Link href={`/stations/${station.code}?date=${date}${to ? `&to=${to}` : ""}`} className="text-sm font-semibold text-brand-600 hover:underline">
              Halaman stasiun →
            </Link>
          </div>
          <div className="mb-4 rounded-2xl border border-line bg-white p-4 shadow-sm sm:p-5">
            <TripPicker stationCode={station.code} stationName={station.name} date={date} to={to} keep={{ station: station.code }} showDate={false} />
          </div>
          <ScheduleBoard key={`${station.code}-${date}-${to ?? ""}`} stationCode={station.code} date={date} to={to} />
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
