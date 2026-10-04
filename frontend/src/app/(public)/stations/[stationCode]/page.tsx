import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { ScheduleBoard } from "@/components/schedule/ScheduleBoard";
import { TripPicker } from "@/components/schedule/TripPicker";
import { ApiError } from "@/lib/api/client";
import { getStation } from "@/lib/api/stations";
import type { Station } from "@/lib/api/types";

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
  const { to: rawTo } = await props.searchParams;
  const station = await loadStation(stationCode);
  const to = typeof rawTo === "string" && /^[A-Za-z0-9-]{1,120}$/.test(rawTo) ? rawTo.toUpperCase() : undefined;

  return (
    <>
      <nav className="mb-3 text-[13px] text-muted sm:mb-4" aria-label="Breadcrumb">
        <Link href="/stations" className="hover:text-ink hover:underline">
          Stasiun
        </Link>{" "}
        <span aria-hidden="true">/</span> <span className="font-medium text-ink">{station.name}</span>
      </nav>

      <div className="mb-4 flex items-center gap-3 sm:mb-6">
        <span className="inline-flex h-10 min-w-10 shrink-0 items-center justify-center rounded-xl bg-ink px-2 text-xs font-bold tracking-wider text-white sm:h-12 sm:min-w-12 sm:text-sm">
          {station.code}
        </span>
        <h1 className="text-xl font-extrabold tracking-tight text-ink sm:text-3xl">Stasiun {station.name}</h1>
      </div>

      <div className="mb-4 rounded-2xl border border-line/80 bg-white p-4 shadow-card sm:mb-6 sm:p-5">
        <TripPicker stationCode={station.code} stationName={station.name} to={to} />
      </div>

      <ScheduleBoard key={`${station.code}-${to ?? ""}`} stationCode={station.code} to={to} />
    </>
  );
}
