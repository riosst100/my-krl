import Link from "next/link";
import { FavoriteDepartures } from "@/components/favorites/FavoriteDepartures";
import { Card } from "@/components/ui";
import { StationSearchForm } from "@/components/schedule/StationSearchForm";
import { getStations } from "@/lib/api/stations";
import type { Station } from "@/lib/api/types";

// Busy interchange stations shown as shortcuts.
const POPULAR = ["MRI", "THB", "SUD", "BKS", "DP", "BOO", "JAKK", "SRP"];

export default async function HomePage() {
  let stations: Station[] = [];
  let failed = false;
  try {
    stations = await getStations();
  } catch {
    failed = true;
  }

  const popular = POPULAR.map((code) => stations.find((s) => s.code === code)).filter((s): s is Station => !!s);

  return (
    <>
      {!failed && stations.length > 0 && <FavoriteDepartures stations={stations} />}

      <div className="grid gap-6 lg:grid-cols-[1fr_400px] lg:items-start lg:gap-8">
      <section className="min-w-0 lg:pt-6">
        <p className="text-xs font-semibold uppercase tracking-wider text-brand-600">KRL Commuter Line</p>
        <h1 className="mt-1.5 text-2xl font-extrabold leading-tight tracking-tight text-ink sm:text-3xl lg:text-4xl">
          Jadwal KRL Jabodetabek, langsung dari stasiun Anda.
        </h1>
        <p className="mt-3 max-w-xl text-sm leading-relaxed text-slate-600 sm:text-base">
          Pilih stasiun dan tanggal untuk melihat semua keberangkatan kereta, arah tujuan, dan perkiraan waktu tiba di
          stasiun akhir.
        </p>

        {popular.length > 0 && (
          <div className="mt-6">
            <h2 className="text-[13px] font-semibold text-slate-700">Stasiun populer</h2>
            {/* Swipeable row on phones, wrapping chips from `sm` up. */}
            <ul className="no-scrollbar -mx-4 mt-2.5 flex gap-2 overflow-x-auto px-4 sm:mx-0 sm:flex-wrap sm:overflow-visible sm:px-0">
              {popular.map((s) => (
                <li key={s.code} className="shrink-0">
                  <Link
                    href={`/stations/${s.code}`}
                    className="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border border-line bg-white px-3 py-1.5 text-[13px] font-medium text-slate-700 shadow-sm transition-colors hover:border-brand-600 hover:text-brand-700"
                  >
                    {s.name}
                    <span className="text-[11px] font-semibold text-muted">{s.code}</span>
                  </Link>
                </li>
              ))}
            </ul>
          </div>
        )}
      </section>

      <Card className="p-4 sm:p-6">
        <h2 className="mb-4 text-base font-bold text-ink sm:mb-5 sm:text-lg">Cari jadwal kereta</h2>
        {failed ? (
          <p role="alert" className="text-sm text-red-700">
            Daftar stasiun tidak dapat dimuat saat ini. Silakan muat ulang halaman beberapa saat lagi.
          </p>
        ) : (
          <StationSearchForm stations={stations} />
        )}
      </Card>
    </div>
    </>
  );
}
