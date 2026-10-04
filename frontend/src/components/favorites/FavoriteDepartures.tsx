"use client";

import Link from "next/link";
import { useState } from "react";
import { FavoriteRoutesDialog } from "@/components/favorites/FavoriteRoutesDialog";
import { UpcomingDepartures } from "@/components/favorites/UpcomingDepartures";
import { Button, Card, ErrorState, LineDot, Skeleton } from "@/components/ui";
import { useToast } from "@/components/ui/Toast";
import { errorMessage } from "@/lib/api/client";
import { getFavoriteRouteDepartures, MAX_FAVORITE_ROUTES, type RouteDepartures } from "@/lib/api/favorites";
import type { Schedule, Station } from "@/lib/api/types";
import { useFavoriteRoutes } from "@/lib/favorites/useFavoriteRoutes";
import { DEPARTED_GRACE_SECONDS, formatCountdown, lineLabel, secondsUntil, serviceBreakNotice, type JakartaClock } from "@/lib/format";
import { useApi } from "@/lib/hooks/useApi";
import { useJakartaClock } from "@/lib/hooks/useJakartaClock";

const DEPARTURES_PER_ROUTE = 2;

/**
 * Homepage block: the next trains of the visitor's favourite routes (1 to 4).
 * Right after signing in, a mandatory dialog asks for the first route.
 */
export function FavoriteDepartures({ stations, guestStation }: { stations: Station[]; guestStation?: string }) {
  const { routes, status, error: favoritesError, missing, save } = useFavoriteRoutes();
  const { toast } = useToast();
  const [editing, setEditing] = useState(false);
  // Ticks every minute: countdowns are recalculated and the departures refreshed.
  const clock = useJakartaClock();

  const key =
    clock && status === "ready" && routes.length > 0 && !missing
      ? `routes|${routes.map((r) => `${r.from.code}-${r.to.code}`).join(",")}|${clock.date}|${clock.seconds}`
      : null;
  const { data, error, loading, reload } = useApi(key, (signal) => getFavoriteRouteDepartures(DEPARTURES_PER_ROUTE, signal));

  const onSave = async (next: Parameters<typeof save>[0]) => {
    await save(next);
    toast("Rute favorit disimpan.", "success");
  };

  // Favourites are an account feature: guests see the soonest departures instead.
  if (status === "guest") return <UpcomingDepartures stations={stations} initialStation={guestStation} />;

  return (
    <section aria-labelledby="favorites-title" className="mb-8 sm:mb-10">
      <div className="mb-3 flex items-end justify-between gap-3 sm:mb-4">
        <div>
          <h2 id="favorites-title" className="text-lg font-bold leading-snug tracking-tight text-ink sm:text-xl">
            Rute Favorit
          </h2>
          <p className="mt-0.5 text-xs text-muted sm:text-sm">Kereta berikutnya untuk rute Anda · diperbarui otomatis · waktu WIB</p>
        </div>
        {status === "ready" && !favoritesError && !missing && (
          <Button variant="secondary" size="sm" onClick={() => setEditing(true)}>
            Ubah rute
          </Button>
        )}
      </div>

      {missing ? (
        <Card className="p-5">
          <p className="text-sm text-muted">Anda belum memilih rute favorit.</p>
          <Button className="mt-3" onClick={() => setEditing(true)}>
            Pilih rute favorit
          </Button>
        </Card>
      ) : favoritesError ? (
        <Card>
          <ErrorState message="Rute favorit tidak dapat dimuat." onRetry={() => window.location.reload()} />
        </Card>
      ) : !clock || status === "loading" || (loading && !data) ? (
        <div className="grid gap-3 sm:gap-4 md:grid-cols-2">
          {Array.from({ length: Math.max(1, Math.min(routes.length || 2, MAX_FAVORITE_ROUTES)) }).map((_, i) => (
            <Card key={i} className="p-5">
              <Skeleton className="h-6 w-40" />
              <Skeleton className="mt-5 h-14 w-full" />
              <Skeleton className="mt-3 h-14 w-full" />
            </Card>
          ))}
        </div>
      ) : error ? (
        <Card>
          <ErrorState message={errorMessage(error)} onRetry={reload} />
        </Card>
      ) : data ? (
        <div className="grid gap-3 sm:gap-4 md:grid-cols-2">
          {data.data.map((item) => (
            <RouteCard key={item.id} item={item} clock={clock} />
          ))}
        </div>
      ) : null}

      <FavoriteRoutesDialog
        open={editing || missing}
        required={missing}
        stations={stations}
        initial={routes}
        onSave={onSave}
        onClose={() => setEditing(false)}
      />
    </section>
  );
}

function RouteCard({ item, clock }: { item: RouteDepartures; clock: JakartaClock }) {
  const { from, to } = item;
  // Trains that left since the last refresh disappear right away.
  const departures = item.departures.filter((s) => secondsUntil(s.service_date, s.departure_time, clock) > -DEPARTED_GRACE_SECONDS);
  const notice = serviceBreakNotice(departures, clock);

  return (
    <Card className="flex flex-col p-4 sm:p-5">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <span className="rounded bg-ink px-1.5 py-0.5 text-[11px] font-bold tracking-wider text-white">
            {from.code} → {to.code}
          </span>
          <h3 className="mt-1.5 text-base font-bold text-ink sm:text-lg">
            {from.name} → {to.name}
          </h3>
        </div>
        <Link href={`/stations/${from.code}?to=${to.code}`} className="shrink-0 text-[13px] font-semibold text-brand-600 hover:underline">
          Semua jadwal →
        </Link>
      </div>

      {departures.length === 0 ? (
        <p className="mt-4 rounded-xl bg-slate-50 px-4 py-6 text-center text-sm text-muted">
          {item.has_schedules_today
            ? "Jadwal KRL selesai sampai jam 12 malam. Kereta mulai berangkat lagi pukul 04:00 WIB."
            : "Jadwal rute ini belum tersedia."}
        </p>
      ) : (
        <>
          {notice && (
            <p role="status" className="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-xs leading-relaxed text-amber-900">
              {notice}
            </p>
          )}
          <ol className="mt-3 space-y-2 sm:mt-4">
            {departures.map((s, i) => (
              <DepartureRow
                key={s.id}
                schedule={s}
                toName={to.name}
                first={i === 0}
                countdown={formatCountdown(secondsUntil(s.service_date, s.departure_time, clock))}
              />
            ))}
          </ol>
        </>
      )}
    </Card>
  );
}

function DepartureRow({ schedule: s, toName, first, countdown }: { schedule: Schedule; toName: string; first: boolean; countdown: string }) {
  return (
    <li className={`flex items-center gap-3 rounded-xl border px-3 py-2.5 sm:gap-4 sm:px-4 sm:py-3 ${first ? "border-brand-100 bg-brand-50/60" : "border-line"}`}>
      <div className="w-[5.25rem] shrink-0">
        <p className="tabular text-xl font-extrabold leading-none text-ink sm:text-2xl">{s.departure_time}</p>
        <p className={`mt-1 text-[11px] font-semibold sm:text-xs ${first ? "text-brand-700" : "text-muted"}`}>{countdown}</p>
      </div>
      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-semibold text-ink sm:text-[15px]">Tujuan akhir {s.destination}</p>
        <p className="mt-0.5 flex items-center gap-1.5 truncate text-xs text-muted">
          <LineDot color={s.color ?? s.line?.color} />
          <span className="tabular">KA {s.train_number}</span>
          <span aria-hidden="true">·</span>
          <span className="truncate">{lineLabel(s.line?.name)}</span>
        </p>
      </div>
      {s.to_station_arrival_time && (
        <p className="tabular max-w-[7rem] shrink-0 text-right text-xs leading-snug text-muted">
          Tiba di {toName}
          <br />
          <span className="text-sm font-semibold text-ink">{s.to_station_arrival_time} WIB</span>
        </p>
      )}
    </li>
  );
}
