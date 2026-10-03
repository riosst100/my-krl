import Link from "next/link";
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
    <div className="grid gap-8 lg:grid-cols-[1fr_400px] lg:items-start">
      <section className="pt-2 lg:pt-8">
        <p className="text-sm font-semibold uppercase tracking-wider text-brand-600">KRL Commuter Line</p>
        <h1 className="mt-2 text-3xl font-extrabold tracking-tight text-ink sm:text-4xl">
          Jadwal KRL Jabodetabek, langsung dari stasiun Anda.
        </h1>
        <p className="mt-4 max-w-xl text-base leading-relaxed text-slate-600">
          Pilih stasiun dan tanggal untuk melihat semua keberangkatan kereta, arah tujuan, dan perkiraan waktu tiba di
          stasiun akhir.
        </p>

        {popular.length > 0 && (
          <div className="mt-8">
            <h2 className="text-sm font-semibold text-ink">Stasiun populer</h2>
            <ul className="mt-3 flex flex-wrap gap-2">
              {popular.map((s) => (
                <li key={s.code}>
                  <Link
                    href={`/stations/${s.code}`}
                    className="inline-flex items-center gap-2 rounded-full border border-line bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:border-brand-600 hover:text-brand-700"
                  >
                    {s.name}
                    <span className="text-xs text-muted">{s.code}</span>
                  </Link>
                </li>
              ))}
            </ul>
          </div>
        )}
      </section>

      <Card className="p-5 sm:p-6">
        <h2 className="mb-5 text-lg font-bold text-ink">Cari jadwal kereta</h2>
        {failed ? (
          <p role="alert" className="text-sm text-red-700">
            Daftar stasiun tidak dapat dimuat saat ini. Silakan muat ulang halaman beberapa saat lagi.
          </p>
        ) : (
          <StationSearchForm stations={stations} />
        )}
      </Card>
    </div>
  );
}
