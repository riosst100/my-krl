"use client";

import Link from "next/link";
import { use, useState, type FormEvent } from "react";
import { Badge, Button, Card, EmptyState, ErrorState, LineDot, PageHeader, Skeleton, TextField } from "@/components/ui";
import { useToast } from "@/components/ui/Toast";
import { getAdminStation, updateStation } from "@/lib/api/admin";
import { ApiError, errorMessage } from "@/lib/api/client";
import type { Station, StationDetail } from "@/lib/api/types";
import { formatDateShort, formatDateTime, formatNumber, lineLabel } from "@/lib/format";
import { useApi } from "@/lib/hooks/useApi";

export default function AdminStationDetailPage({ params }: PageProps<"/admin/stations/[id]">) {
  const { id } = use(params);
  const { data, error, loading, reload } = useApi(`station|${id}`, () => getAdminStation(id));
  const [patch, setPatch] = useState<Partial<Station>>({});

  if (error) {
    return (
      <>
        <BackLink />
        <Card>
          <ErrorState message={errorMessage(error)} onRetry={reload} />
        </Card>
      </>
    );
  }

  if (loading || !data) {
    return (
      <>
        <BackLink />
        <Skeleton className="h-8 w-64" />
        <div className="mt-6 grid gap-6 lg:grid-cols-3">
          <Skeleton className="h-64" />
          <Skeleton className="h-64 lg:col-span-2" />
        </div>
      </>
    );
  }

  const station: StationDetail = { ...data, ...patch };

  return (
    <>
      <BackLink />
      <PageHeader
        title={`${station.name}`}
        description={
          <span className="flex flex-wrap items-center gap-2">
            <span className="rounded bg-ink px-1.5 py-0.5 text-xs font-bold text-white">{station.code}</span>
            <Badge tone={station.is_active ? "success" : "neutral"}>{station.is_active ? "Tampil di situs" : "Nonaktif"}</Badge>
            {!station.kci_enabled && <Badge tone="warning">Tidak beroperasi menurut KCI</Badge>}
          </span>
        }
        actions={
          station.is_active ? (
            <Link href={`/stations/${station.code}`} target="_blank" className="text-sm font-semibold text-brand-600 hover:underline">
              Lihat halaman publik ↗
            </Link>
          ) : undefined
        }
      />

      <div className="grid gap-6 lg:grid-cols-3">
        <div className="space-y-6">
          <Card className="p-5">
            <h2 className="font-semibold text-ink">Data stasiun</h2>
            <dl className="mt-4 space-y-3 text-sm">
              <Row label="Kode KCI" value={station.code} />
              <Row label="Nama" value={station.name} />
              <Row label="Slug (URL)" value={station.slug} />
              <Row
                label="Wilayah operasi"
                value={
                  station.operational_area === null
                    ? "—"
                    : `${station.operational_area_name ?? "Wilayah"} (${station.operational_area})`
                }
              />
              <Row label="Status KCI" value={station.kci_enabled ? "Beroperasi" : "Tidak beroperasi"} />
              <Row label="Disinkronkan" value={formatDateTime(station.synced_at)} />
              <Row label="Jadwal diperbarui" value={formatDateTime(station.last_schedule_update)} />
            </dl>
          </Card>

          <CoordinatesForm station={station} onSaved={(s) => setPatch((p) => ({ ...p, latitude: s.latitude, longitude: s.longitude }))} />
        </div>

        <div className="space-y-6 lg:col-span-2">
          <Card className="p-5">
            <h2 className="font-semibold text-ink">Line yang melayani</h2>
            {station.lines.length === 0 ? (
              <p className="mt-3 text-sm text-muted">Belum ada data jadwal untuk stasiun ini.</p>
            ) : (
              <ul className="mt-3 flex flex-wrap gap-2">
                {station.lines.map((line) => (
                  <li key={line.id} className="inline-flex items-center gap-2 rounded-full border border-line px-3 py-1.5 text-sm">
                    <LineDot color={line.color} />
                    <span className="font-medium text-ink">{lineLabel(line.name)}</span>
                    <span className="font-mono text-xs text-muted">{line.color}</span>
                  </li>
                ))}
              </ul>
            )}

            {station.destinations_today.length > 0 && (
              <>
                <h3 className="mt-6 text-sm font-semibold text-ink">Tujuan hari ini</h3>
                <ul className="mt-2 flex flex-wrap gap-2 text-sm">
                  {station.destinations_today.map((d) => (
                    <li key={d.destination} className="rounded-lg bg-slate-100 px-2.5 py-1 text-slate-700">
                      → {d.destination} <span className="tabular text-muted">· {d.trains}</span>
                    </li>
                  ))}
                </ul>
              </>
            )}
          </Card>

          <Card className="overflow-hidden">
            <h2 className="border-b border-line px-5 py-3 font-semibold text-ink">Jadwal tersimpan per tanggal</h2>
            {station.schedules_by_date.length === 0 ? (
              <EmptyState
                title="Belum ada jadwal"
                description={station.is_active ? "Jadwal akan terisi setelah sinkronisasi jadwal berikutnya." : "Stasiun nonaktif tidak disinkronkan jadwalnya."}
              />
            ) : (
              <table className="w-full text-left text-sm">
                <thead className="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-muted">
                  <tr>
                    <th scope="col" className="px-5 py-3">Tanggal</th>
                    <th scope="col" className="px-5 py-3">Kereta</th>
                    <th scope="col" className="px-5 py-3">Pertama</th>
                    <th scope="col" className="px-5 py-3">Terakhir</th>
                    <th scope="col" className="px-5 py-3"><span className="sr-only">Aksi</span></th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-line">
                  {station.schedules_by_date.map((d) => (
                    <tr key={d.date}>
                      <td className="px-5 py-2.5 font-medium text-ink">{formatDateShort(d.date)}</td>
                      <td className="tabular px-5 py-2.5">{formatNumber(d.trains)}</td>
                      <td className="tabular px-5 py-2.5 text-slate-600">{d.first_departure}</td>
                      <td className="tabular px-5 py-2.5 text-slate-600">{d.last_departure}</td>
                      <td className="px-5 py-2.5 text-right">
                        <Link
                          href={`/admin/schedules?station=${station.code}&date=${d.date}`}
                          className="font-semibold text-brand-600 hover:underline"
                        >
                          Lihat
                        </Link>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </Card>
        </div>
      </div>
    </>
  );
}

function CoordinatesForm({ station, onSaved }: { station: Station; onSaved: (s: Station) => void }) {
  const { toast } = useToast();
  const [lat, setLat] = useState(station.latitude?.toString() ?? "");
  const [lng, setLng] = useState(station.longitude?.toString() ?? "");
  const [error, setError] = useState<ApiError | null>(null);
  const [saving, setSaving] = useState(false);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setSaving(true);
    setError(null);
    try {
      const saved = await updateStation(station.id, {
        latitude: lat.trim() === "" ? null : Number(lat),
        longitude: lng.trim() === "" ? null : Number(lng),
      });
      onSaved(saved);
      toast("Koordinat disimpan.", "success");
    } catch (err) {
      if (err instanceof ApiError && err.isValidation) setError(err);
      else toast(errorMessage(err), "error");
    } finally {
      setSaving(false);
    }
  };

  return (
    <Card className="p-5">
      <h2 className="font-semibold text-ink">Koordinat</h2>
      <p className="mt-1 text-xs text-muted">KCI tidak menyediakan koordinat. Isi manual; sinkronisasi tidak akan menimpanya.</p>
      <form onSubmit={submit} className="mt-4 space-y-3" noValidate>
        <TextField label="Latitude" inputMode="decimal" placeholder="-6.2099" value={lat} onChange={(e) => setLat(e.target.value)} error={error?.field("latitude")} />
        <TextField label="Longitude" inputMode="decimal" placeholder="106.8502" value={lng} onChange={(e) => setLng(e.target.value)} error={error?.field("longitude")} />
        <Button type="submit" variant="secondary" loading={saving}>
          Simpan koordinat
        </Button>
      </form>
    </Card>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between gap-4">
      <dt className="text-muted">{label}</dt>
      <dd className="text-right font-medium text-ink">{value}</dd>
    </div>
  );
}

function BackLink() {
  return (
    <Link href="/admin/stations" className="mb-4 inline-block text-sm text-muted hover:text-ink">
      ← Semua stasiun
    </Link>
  );
}
