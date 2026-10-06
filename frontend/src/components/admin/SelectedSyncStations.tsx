"use client";

import Link from "next/link";
import { Card, Skeleton } from "@/components/ui";
import { getSyncStationsSetting } from "@/lib/api/admin";
import { useApi } from "@/lib/hooks/useApi";

/** The stations chosen in Configuration → Sync Configuration, with a link there. */
export function SelectedSyncStations({ subject }: { subject: string }) {
  const { data } = useApi("sync-stations-setting", () =>
    getSyncStationsSetting(),
  );

  return (
    <Card className="mb-6 p-5">
      <h2 className="font-semibold text-ink">Stasiun terpilih</h2>
      {!data ? (
        <Skeleton className="mt-3 h-6 w-64" />
      ) : (
        <p className="mt-1 text-sm text-slate-600">
          {subject}{" "}
          {data.stations.length > 0
            ? `${data.stations.join(", ")}.`
            : `semua stasiun aktif (${data.active_stations}).`}{" "}
          <Link
            href="/admin/configuration/sync"
            className="font-semibold text-brand-700 hover:underline"
          >
            Ubah di Sync Configuration
          </Link>
        </p>
      )}
    </Card>
  );
}
