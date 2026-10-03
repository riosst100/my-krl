import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { ScheduleBoard } from "@/components/schedule/ScheduleBoard";
import { TripPicker } from "@/components/schedule/TripPicker";
import { ApiError } from "@/lib/api/client";
import { getStation } from "@/lib/api/stations";
import type { Station } from "@/lib/api/types";
import { isValidDate, todayInJakarta } from "@/lib/format";

async function loadStation(code: string): Promise<Station> {
  try {
    return await getStation(code);
  } catch (error) {
    if (error instanceof ApiError && error.isNotFound) notFound();
    throw error; // handled by error.tsx
  }
}

export async function generateMetadata(props: PageProps<"/stations/[stationCode]">): Promise<Metadata> {
  const { stationCode } = await props.params;
  try {
    const station = await getStation(stationCode);
    return { title: `Jadwal KRL Stasiun ${station.name}` };
  } catch {
    return { title: "Stasiun" };
  }
}

export default async function StationPage(props: PageProps<"/stations/[stationCode]">) {
  const { stationCode } = await props.params;
  const { date: rawDate, to: rawTo } = await props.searchParams;
  const station = await loadStation(stationCode);
  const date = typeof rawDate === "string" && isValidDate(rawDate) ? rawDate : todayInJakarta();
  const to = typeof rawTo === "string" && /^[A-Za-z0-9-]{1,120}$/.test(rawTo) ? rawTo.toUpperCase() : undefined;

  return (
    <>
      <nav className="mb-4 text-sm text-muted" aria-label="Breadcrumb">
        <Link href="/stations" className="hover:text-ink hover:underline">
          Stasiun
        </Link>{" "}
        / <span className="text-ink">{station.name}</span>
      </nav>

      <div className="mb-6">
        <span className="inline-block rounded-md bg-ink px-2 py-0.5 text-xs font-bold tracking-wider text-white">{station.code}</span>
        <h1 className="mt-2 text-3xl font-extrabold tracking-tight text-ink">Stasiun {station.name}</h1>
      </div>

      <div className="mb-6 rounded-2xl border border-line bg-white p-4 shadow-sm sm:p-5">
        <TripPicker stationCode={station.code} stationName={station.name} date={date} to={to} />
      </div>

      <ScheduleBoard key={`${station.code}-${date}-${to ?? ""}`} stationCode={station.code} date={date} to={to} />
    </>
  );
}
