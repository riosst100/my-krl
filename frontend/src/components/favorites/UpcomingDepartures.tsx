"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { StationCombobox } from "@/components/schedule/StationCombobox";
import { Card, EmptyState, ErrorState, LineDot, Skeleton } from "@/components/ui";
import { errorMessage } from "@/lib/api/client";
import { getUpcomingDepartures } from "@/lib/api/favorites";
import type { Station } from "@/lib/api/types";
import { DEPARTED_GRACE_SECONDS, formatCountdown, lineLabel, secondsUntil, serviceBreakNotice } from "@/lib/format";
import { useApi } from "@/lib/hooks/useApi";
import { useJakartaClock } from "@/lib/hooks/useJakartaClock";

const LIMIT = 5;

/**
 * Homepage for guests: the soonest departures from now (WIB), across all
 * stations or from the chosen departure station (?dari=THB), plus an
 * invitation to sign up for favourites.
 */
export function UpcomingDepartures({ stations, initialStation = "" }: { stations: Station[]; initialStation?: string }) {
  const router = useRouter();
  const [from, setFrom] = useState(stations.some((s) => s.code === initialStation) ? initialStation : "");
  // Ticks every minute: countdowns are recalculated and the list is refreshed.
  const clock = useJakartaClock();

  const { data, error, loading, reload } = useApi(clock ? `upcoming|${from}|${clock.date}|${clock.seconds}` : null, (signal) =>
    getUpcomingDepartures(LIMIT, from || undefined, signal),
  );
  const departures = clock
    ? (data?.data ?? []).filter((s) => secondsUntil(s.service_date, s.departure_time, clock) > -DEPARTED_GRACE_SECONDS)
    : [];
  const notice = clock ? serviceBreakNotice(departures, clock) : null;

  const chooseStation = (code: string) => {
    setFrom(code);
    router.replace(code ? `/?dari=${code}` : "/", { scroll: false });
  };

  const selected = stations.find((s) => s.code === from);

  return (
    <section aria-labelledby="upcoming-title" className="mb-8 sm:mb-10">
      {/* Departure station first: it decides what the list below shows. */}
      <div className="mb-5 sm:max-w-md">
        <StationCombobox label="Stasiun keberangkatan" stations={stations} value={from} onChange={chooseStation} />
      </div>

      <div className="mb-3 sm:mb-4">
        <h2 id="upcoming-title" className="text-lg font-bold tracking-tight text-ink sm:text-xl">
          Kereta terdekat{selected ? ` dari ${selected.name}` : ""}
        </h2>
        <p className="mt-0.5 text-xs text-muted sm:text-sm">Keberangkatan berikutnya mulai sekarang · diperbarui otomatis · waktu WIB</p>
      </div>

      <div className="grid gap-4 lg:grid-cols-[1fr_320px]">
        <Card className="overflow-hidden">
          {!clock || (loading && !data) ? (
            <div className="space-y-3 p-4" aria-busy="true">
              {Array.from({ length: LIMIT }).map((_, i) => (
                <Skeleton key={i} className="h-12 w-full" />
              ))}
            </div>
          ) : error ? (
            <ErrorState message={errorMessage(error)} onRetry={reload} />
          ) : departures.length === 0 ? (
            data?.meta.has_schedules_today ? (
              <EmptyState
                title={`Tidak ada lagi kereta hari ini${selected ? ` dari ${selected.name}` : ""}`}
                description="Jadwal KRL selesai sampai jam 12 malam. Kereta mulai berangkat lagi pukul 04:00 WIB; jadwal besok tampil setelah sinkronisasi pukul 00:00 WIB."
              />
            ) : selected ? (
              <EmptyState
                title={`Belum ada jadwal dari ${selected.name}`}
                description="Jadwal stasiun ini belum disinkronkan. Pilih stasiun lain atau lihat semua stasiun."
                action={
                  <button type="button" onClick={() => chooseStation("")} className="text-sm font-semibold text-brand-600 hover:underline">
                    Tampilkan semua stasiun
                  </button>
                }
              />
            ) : (
              <EmptyState title="Belum ada jadwal" description="Jadwal belum disinkronkan. Coba beberapa saat lagi." />
            )
          ) : (
            <>
            {notice && (
              <p role="status" className="border-b border-amber-200 bg-amber-50 px-4 py-3 text-[13px] leading-relaxed text-amber-900">
                {notice}
              </p>
            )}
            <ol className="divide-y divide-line">
              {departures.map((s, i) => {
                const countdown = formatCountdown(secondsUntil(s.service_date, s.departure_time, clock));
                return (
                  <li key={s.id} className={`flex items-center gap-3 border-l-[3px] py-3 pr-4 pl-[13px] ${i === 0 ? "border-brand-600 bg-brand-50/60" : "border-transparent"}`}>
                    <div className="w-[5.25rem] shrink-0">
                      <p className="tabular text-lg font-extrabold leading-none text-ink sm:text-xl">{s.departure_time}</p>
                      <p className={`mt-1 text-[11px] font-semibold sm:text-xs ${i === 0 ? "text-brand-700" : "text-muted"}`}>
                        {countdown}
                      </p>
                    </div>
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-semibold text-ink sm:text-[15px]">
                        {s.station && !selected ? (
                          <Link href={`/stations/${s.station.code}`} className="hover:text-brand-700 hover:underline">
                            {s.station.name}
                          </Link>
                        ) : null}{" "}
                        → {s.destination}
                      </p>
                      <p className="mt-0.5 flex items-center gap-1.5 truncate text-xs text-muted">
                        <LineDot color={s.color ?? s.line?.color} />
                        <span className="tabular">KA {s.train_number}</span>
                        <span aria-hidden="true">·</span>
                        <span className="truncate">{lineLabel(s.line?.name)}</span>
                      </p>
                    </div>
                    {s.destination_arrival_time && (
                      <p className="tabular hidden shrink-0 text-right text-xs text-muted sm:block">
                        tiba
                        <br />
                        <span className="text-sm font-semibold text-ink">{s.destination_arrival_time}</span>
                      </p>
                    )}
                  </li>
                );
              })}
            </ol>
            </>
          )}
        </Card>

        <Card className="flex flex-col justify-between gap-4 self-start bg-gradient-to-br from-white to-brand-50/60 p-4 sm:p-5">
          <div>
            <h3 className="text-[15px] font-bold text-ink">Pakai stasiun favorit Anda</h3>
            <p className="mt-1 text-[13px] leading-relaxed text-muted">
              Daftar dan pilih 2 stasiun favorit — kereta berikutnya dari kedua stasiun itu langsung tampil di sini.
            </p>
          </div>
          <div className="flex gap-2">
            <Link href="/register" className="flex-1 rounded-xl bg-brand-600 px-4 py-2.5 text-center text-sm font-semibold text-white shadow-sm shadow-brand-600/20 transition-colors hover:bg-brand-700">
              Daftar
            </Link>
            <Link href="/login" className="flex-1 rounded-xl border border-line bg-white px-4 py-2.5 text-center text-sm font-semibold text-ink shadow-sm transition-colors hover:bg-slate-50">
              Masuk
            </Link>
          </div>
        </Card>
      </div>
    </section>
  );
}
