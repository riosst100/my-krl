"use client";

import Link from "next/link";
import { useState } from "react";
import { FavoriteRoutesDialog } from "@/components/favorites/FavoriteRoutesDialog";
import { DepartureRow } from "@/components/favorites/DepartureRow";
import { TripDetailDialog } from "@/components/favorites/TripDetailDialog";
import { Button, Card, ErrorState, Skeleton } from "@/components/ui";
import { useToast } from "@/components/ui/Toast";
import { errorMessage } from "@/lib/api/client";
import {
  getFavoriteRouteDepartures,
  MAX_FAVORITE_ROUTES,
  type RouteDepartures,
} from "@/lib/api/favorites";
import type { Schedule, Station } from "@/lib/api/types";
import { useFavoriteRoutes } from "@/lib/favorites/useFavoriteRoutes";
import {
  DEPARTED_GRACE_SECONDS,
  formatCountdown,
  secondsUntil,
  serviceBreakNotice,
  type JakartaClock,
} from "@/lib/format";
import { useApi } from "@/lib/hooks/useApi";
import { useJakartaClock } from "@/lib/hooks/useJakartaClock";

const DEPARTURES_PER_ROUTE = 2;

/**
 * Homepage block: the next trains of the visitor's favourite routes (1 to 4).
 * Right after signing in, a mandatory dialog asks for the first route.
 */
export function FavoriteDepartures({ stations }: { stations: Station[] }) {
  const {
    routes,
    status,
    error: favoritesError,
    missing,
    save,
  } = useFavoriteRoutes();
  const { toast } = useToast();
  const [editing, setEditing] = useState(false);
  // Ticks every minute: countdowns are recalculated and the departures refreshed.
  const clock = useJakartaClock();

  const key =
    clock && status === "ready" && routes.length > 0 && !missing
      ? `routes|${routes.map((r) => `${r.from.code}-${r.to.code}`).join(",")}|${clock.date}|${clock.seconds}`
      : null;
  const { data, error, loading, reload } = useApi(key, (signal) =>
    getFavoriteRouteDepartures(DEPARTURES_PER_ROUTE, signal),
  );

  const onSave = async (next: Parameters<typeof save>[0]) => {
    await save(next);
    toast("Rute favorit disimpan.", "success");
  };

  // Favourites are an account feature: guests get an invitation instead.
  if (status === "guest") return <GuestPrompt />;

  return (
    <section aria-labelledby="favorites-title" className="mb-8 sm:mb-10">
      <div className="mb-3 flex items-center justify-between gap-3 sm:mb-4">
        <div className="min-w-0">
          <h2
            id="favorites-title"
            className="text-base font-bold leading-snug tracking-tight text-ink sm:text-xl"
          >
            Rute Favorit
          </h2>
          <p className="mt-0.5 text-xs text-muted sm:text-sm">
            <span className="hidden sm:inline">Kereta berikutnya · </span>
            Diperbarui otomatis · WIB
          </p>
        </div>
        {status === "ready" && !favoritesError && !missing && (
          <Button
            variant="secondary"
            size="sm"
            className="shrink-0 whitespace-nowrap px-3 text-xs sm:text-[13px]"
            onClick={() => setEditing(true)}
          >
            Ubah Rute Favorit
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
          <ErrorState
            message="Rute favorit tidak dapat dimuat."
            onRetry={() => window.location.reload()}
          />
        </Card>
      ) : !clock || status === "loading" || (loading && !data) ? (
        <div className="grid gap-3 sm:gap-4 md:grid-cols-2">
          {Array.from({
            length: Math.max(
              1,
              Math.min(routes.length || 2, MAX_FAVORITE_ROUTES),
            ),
          }).map((_, i) => (
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

function RouteCard({
  item,
  clock,
}: {
  item: RouteDepartures;
  clock: JakartaClock;
}) {
  const { from, to } = item;
  // Trains that left since the last refresh disappear right away.
  const departures = item.departures.filter(
    (s) =>
      secondsUntil(s.service_date, s.departure_time, clock) >
      -DEPARTED_GRACE_SECONDS,
  );
  const notice = serviceBreakNotice(departures, clock);
  const [selected, setSelected] = useState<Schedule | null>(null);

  return (
    <Card className="flex flex-col p-3.5 sm:p-5">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <h3 className="text-[15px] font-bold leading-snug text-ink sm:text-lg">
            {from.name} <span className="text-muted">→</span> {to.name}
          </h3>
        </div>
        <Link
          href={`/stations/${from.code}?to=${to.code}`}
          className="shrink-0 pt-0.5 text-xs font-semibold text-brand-600 hover:underline sm:text-[13px]"
        >
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
            <p
              role="status"
              className="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-xs leading-relaxed text-amber-900"
            >
              {notice}
            </p>
          )}
          <ol className="mt-3">
            {departures.map((s, i) => (
              <DepartureRow
                key={s.id}
                schedule={s}
                fromName={from.name}
                toName={to.name}
                onOpen={() => setSelected(s)}
                first={i === 0}
                countdown={formatCountdown(
                  secondsUntil(s.service_date, s.departure_time, clock),
                )}
              />
            ))}
          </ol>
        </>
      )}
      <TripDetailDialog
        schedule={selected}
        from={from}
        to={to}
        onClose={() => setSelected(null)}
      />
    </Card>
  );
}

function GuestPrompt() {
  return (
    <section aria-labelledby="favorites-title" className="mb-8 sm:mb-10">
      <Card className="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
        <div className="min-w-0">
          <h2
            id="favorites-title"
            className="text-base font-bold tracking-tight text-ink sm:text-lg"
          >
            Masuk untuk menambahkan rute favorit Anda
          </h2>
          <p className="mt-1 text-[13px] leading-relaxed text-muted sm:text-sm">
            Simpan rute yang sering Anda pakai, sampai 4 rute. Kereta berikutnya
            langsung tampil di beranda, tanpa perlu mencari lagi.
          </p>
        </div>
        <div className="flex shrink-0 gap-2">
          <Link
            href="/login"
            className="flex-1 rounded-xl bg-brand-600 px-4 py-2.5 text-center text-sm font-semibold text-white shadow-sm shadow-brand-600/20 transition-colors hover:bg-brand-700 sm:flex-none"
          >
            Masuk
          </Link>
          <Link
            href="/register"
            className="flex-1 rounded-xl border border-line bg-white px-4 py-2.5 text-center text-sm font-semibold text-ink shadow-sm transition-colors hover:bg-slate-50 sm:flex-none"
          >
            Daftar
          </Link>
        </div>
      </Card>
    </section>
  );
}
