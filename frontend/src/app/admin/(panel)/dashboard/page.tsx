"use client";

import Link from "next/link";
import { SyncStatusBadge, TRIGGER_LABELS } from "@/components/admin/SyncStatusBadge";
import { Card, ErrorState, PageHeader, Skeleton } from "@/components/ui";
import { getDashboard } from "@/lib/api/admin";
import { errorMessage } from "@/lib/api/client";
import { formatDateTime, formatNumber } from "@/lib/format";
import { useApi } from "@/lib/hooks/useApi";

export default function DashboardPage() {
  const { data, error, loading, reload } = useApi("dashboard", () => getDashboard());

  return (
    <>
      <PageHeader title="Dashboard" description="Ringkasan data dan status sinkronisasi KCI." />

      {error ? (
        <Card>
          <ErrorState message={errorMessage(error)} onRetry={reload} />
        </Card>
      ) : (
        <>
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <Stat label="Total Pengguna" value={data?.total_users} sub={data ? `${data.total_admins} admin` : undefined} loading={loading} />
            <Stat
              label="Total Stasiun"
              value={data?.total_stations}
              sub={data ? `${data.active_stations} aktif` : undefined}
              loading={loading}
            />
            <Stat
              label="Total Jadwal"
              value={data?.total_schedules}
              sub={data ? `${formatNumber(data.schedules_today)} hari ini` : undefined}
              loading={loading}
            />
            <Card className="p-5">
              <p className="text-sm font-medium text-muted">Status Sinkronisasi</p>
              {loading || !data ? (
                <Skeleton className="mt-3 h-7 w-24" />
              ) : data.last_sync ? (
                <div className="mt-2">
                  <SyncStatusBadge status={data.last_sync.status} />
                  <p className="mt-2 text-xs text-muted">
                    {TRIGGER_LABELS[data.last_sync.trigger]} · {formatDateTime(data.last_sync.finished_at ?? data.last_sync.created_at)}
                  </p>
                </div>
              ) : (
                <p className="mt-2 text-sm text-ink">Belum pernah</p>
              )}
            </Card>
          </div>

          <Card className="mt-6 p-5 sm:p-6">
            <div className="flex items-center justify-between gap-4">
              <h2 className="font-semibold text-ink">Sinkronisasi jadwal terakhir</h2>
              <Link href="/admin/sync" className="text-sm font-semibold text-brand-600 hover:underline">
                Kelola →
              </Link>
            </div>
            {loading || !data ? (
              <Skeleton className="mt-4 h-16 w-full" />
            ) : data.last_sync ? (
              <dl className="mt-4 grid gap-4 text-sm sm:grid-cols-5">
                <Item label="Mulai" value={formatDateTime(data.last_sync.started_at)} />
                <Item label="Selesai" value={formatDateTime(data.last_sync.finished_at)} />
                <Item label="Data diproses" value={formatNumber(data.last_sync.records_processed)} />
                <Item label="Sumber" value={data.last_sync.source ?? "—"} />
                <Item
                  label="Sync stasiun (bulanan)"
                  value={data.last_station_sync ? formatDateTime(data.last_station_sync.finished_at ?? data.last_station_sync.created_at) : "Belum pernah"}
                />
                {data.last_sync.error_message && (
                  <div className="sm:col-span-5">
                    <dt className="text-muted">Pesan error</dt>
                    <dd className="mt-1 rounded-lg bg-red-50 p-3 font-mono text-xs text-red-800">{data.last_sync.error_message}</dd>
                  </div>
                )}
              </dl>
            ) : (
              <p className="mt-4 text-sm text-muted">Belum ada sinkronisasi.</p>
            )}
          </Card>
        </>
      )}
    </>
  );
}

function Stat({ label, value, sub, loading }: { label: string; value?: number; sub?: string; loading: boolean }) {
  return (
    <Card className="p-5">
      <p className="text-sm font-medium text-muted">{label}</p>
      {loading || value === undefined ? (
        <Skeleton className="mt-3 h-8 w-20" />
      ) : (
        <>
          <p className="tabular mt-1 text-3xl font-bold text-ink">{formatNumber(value)}</p>
          {sub && <p className="mt-1 text-xs text-muted">{sub}</p>}
        </>
      )}
    </Card>
  );
}

function Item({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-muted">{label}</dt>
      <dd className="mt-0.5 font-medium text-ink">{value}</dd>
    </div>
  );
}
