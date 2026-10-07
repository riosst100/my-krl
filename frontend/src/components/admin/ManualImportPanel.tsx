"use client";

import { useId, useState, type FormEvent } from "react";
import { Alert, Button, Card, SelectField } from "@/components/ui";
import { useToast } from "@/components/ui/Toast";
import { importKciJson, type ImportType } from "@/lib/api/admin";
import { ApiError, errorMessage } from "@/lib/api/client";
import type { Station } from "@/lib/api/types";

/** KCI's web API (the same URLs krl-sync fetches). */
const KCI_URLS: Record<ImportType, string> = {
  schedules:
    "https://www.kci.id/api/krl/schedules?stationid={station}&timefrom=00%3A00&timeto=23%3A59",
  train_stops: "https://www.kci.id/api/krl/train-schedule?trainid={train}",
  stations: "https://www.kci.id/api/krl/stations",
};

/** The API URL with the station code / train number filled in (same rules as krl-sync). */
function withParam(
  url: string,
  param: "stationid" | "trainid",
  placeholder: "{station}" | "{train}",
  value: string,
) {
  if (url.includes(placeholder))
    return url.replace(placeholder, encodeURIComponent(value));
  return url.replace(
    new RegExp(`([?&]${param}=)[^&#]*`, "i"),
    `$1${encodeURIComponent(value)}`,
  );
}

const TYPES: Record<
  ImportType,
  { label: string; hint: string; sample: string }
> = {
  schedules: {
    label: "Jadwal satu stasiun",
    hint: "Response dari /api/krl/schedules?stationid=KODE. Mengganti jadwal stasiun ini (hari ini dan hari sebelumnya); stasiun lain tidak berubah.",
    sample:
      '{"status":200,"data":[{"train_id":"5198C","ka_name":"COMMUTER LINE CIKARANG","route_name":"ANGKE-CIKARANG","dest":"CIKARANG","time_est":"06:01:00","color":"#0084D8","dest_time":"07:04:00"}]}',
  },
  train_stops: {
    label: "Pemberhentian satu kereta",
    hint: "Response dari /api/krl/train-schedule?trainid=NOMOR. Dipakai untuk “Ke stasiun” dan “Lihat perjalanan”.",
    sample:
      '{"status":200,"data":[{"train_id":"5198C","station_id":"THB","time_est":"06:01:00","transit_station":false}]}',
  },
  stations: {
    label: "Daftar stasiun",
    hint: "Response dari /api/krl/stations. Stasiun baru ditambahkan, yang lama diperbarui (status aktif tidak diubah).",
    sample:
      '{"status":200,"data":[{"sta_id":"THB","sta_name":"TANAHABANG","group_wil":0,"fg_enable":1}]}',
  },
};

/**
 * Admin → Sinkronisasi: paste the JSON of a KCI API response by hand. Needs no
 * access to KCI, so it works on the local machine and on the server.
 */
export function ManualImportPanel({
  stations,
  onImported,
}: {
  stations: Station[];
  onImported: () => void;
}) {
  const { toast } = useToast();
  const id = useId();
  const [type, setType] = useState<ImportType>("schedules");
  const [station, setStation] = useState("");
  const [train, setTrain] = useState("");
  const [json, setJson] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<ApiError | string | null>(null);
  const [done, setDone] = useState<string | null>(null);

  const info = TYPES[type];

  // The KCI URL to open by hand, with the chosen station / train.
  const base = KCI_URLS[type];
  let sourceUrl: string | null = null;
  if (type === "schedules")
    sourceUrl = station
      ? withParam(base, "stationid", "{station}", station)
      : null;
  else if (type === "train_stops")
    sourceUrl = train.trim()
      ? withParam(base, "trainid", "{train}", train.trim())
      : null;
  else sourceUrl = base;
  const missing = type === "schedules" ? "Pilih stasiun" : "Isi nomor kereta";

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setBusy(true);
    setError(null);
    setDone(null);
    try {
      const result = await importKciJson({
        type,
        station: station || undefined,
        train: train.trim() || undefined,
        json,
      });
      setDone(result.message);
      toast("Import berhasil.", "success");
      onImported();
    } catch (err) {
      setError(
        err instanceof ApiError && err.isValidation ? err : errorMessage(err),
      );
    } finally {
      setBusy(false);
    }
  };

  const fieldError = (name: string) =>
    error instanceof ApiError ? error.field(name) : undefined;
  const general =
    typeof error === "string"
      ? error
      : error instanceof ApiError && !error.isValidation
        ? error.message
        : null;

  return (
    <Card className="mb-6 p-5">
      <h2 className="font-semibold text-ink">Import manual (JSON)</h2>
      <p className="mt-1 text-sm text-muted">
        Kalau API KCI tidak bisa diakses, buka URL API-nya di browser, salin
        hasilnya, lalu tempel di sini. Data disimpan di server ini.
      </p>

      <form onSubmit={submit} className="mt-4 space-y-4" noValidate>
        <div className="grid gap-4 sm:grid-cols-2">
          <SelectField
            label="Jenis data"
            value={type}
            onChange={(e) => {
              setType(e.target.value as ImportType);
              setError(null);
              setDone(null);
            }}
          >
            {Object.entries(TYPES).map(([value, t]) => (
              <option key={value} value={value}>
                {t.label}
              </option>
            ))}
          </SelectField>

          {type === "schedules" && (
            <SelectField
              label="Stasiun"
              value={station}
              onChange={(e) => setStation(e.target.value)}
              error={fieldError("station")}
              required
            >
              <option value="">Pilih stasiun…</option>
              {stations.map((s) => (
                <option key={s.code} value={s.code}>
                  {s.name} ({s.code})
                </option>
              ))}
            </SelectField>
          )}

          {type === "train_stops" && (
            <div>
              <label
                htmlFor={`${id}-train`}
                className="mb-1.5 block text-[13px] font-semibold text-slate-700"
              >
                Nomor kereta (opsional)
              </label>
              <input
                id={`${id}-train`}
                value={train}
                onChange={(e) => setTrain(e.target.value)}
                placeholder="mis. 5198C"
                className="block h-11 w-full rounded-xl border border-line bg-white px-3.5 text-base text-ink shadow-sm placeholder:text-slate-400 focus:border-brand-600 focus:outline-none focus:ring-4 focus:ring-brand-600/10 sm:text-sm"
              />
              {fieldError("train") ? (
                <p className="mt-1.5 text-xs font-medium text-red-600">
                  {fieldError("train")}
                </p>
              ) : (
                <p className="mt-1.5 text-xs text-muted">
                  Kosongkan jika baris JSON memuat “train_id”.
                </p>
              )}
            </div>
          )}
        </div>

        {/* Step 1: open the KCI URL and copy what it shows. */}
        <div className="rounded-xl border border-line bg-slate-50/60 p-3.5">
          <p className="text-[13px] font-semibold text-slate-700">
            1. Buka URL API KCI, lalu salin hasilnya
          </p>
          {sourceUrl ? (
            <>
              <a
                href={sourceUrl}
                target="_blank"
                rel="noopener noreferrer"
                className="mt-1.5 block break-all text-[13px] font-medium text-brand-600 hover:underline"
              >
                {sourceUrl}
              </a>
              <div className="mt-2 flex flex-wrap gap-2">
                <a
                  href={sourceUrl}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="inline-flex h-9 items-center rounded-lg border border-line bg-white px-3 text-[13px] font-semibold text-ink shadow-sm hover:bg-slate-50"
                >
                  Buka di tab baru ↗
                </a>
                <Button
                  type="button"
                  variant="secondary"
                  size="sm"
                  onClick={() =>
                    navigator.clipboard?.writeText(sourceUrl).then(
                      () => toast("URL disalin.", "success"),
                      () => toast("Tidak bisa menyalin URL.", "error"),
                    )
                  }
                >
                  Salin URL
                </Button>
              </div>
            </>
          ) : (
            <p className="mt-1.5 text-xs text-muted">
              {base
                ? `${missing} untuk menampilkan link.`
                : "URL belum diatur. Isi di pengaturan sumber data."}
            </p>
          )}
        </div>

        <div>
          <label
            htmlFor={`${id}-json`}
            className="mb-1.5 block text-[13px] font-semibold text-slate-700"
          >
            2. Tempel JSON hasilnya di sini
          </label>
          <textarea
            id={`${id}-json`}
            value={json}
            onChange={(e) => {
              setJson(e.target.value);
              setError(null);
            }}
            rows={8}
            spellCheck={false}
            placeholder={info.sample}
            aria-invalid={!!fieldError("json") || undefined}
            className="block w-full rounded-xl border border-line bg-white px-3.5 py-2.5 font-mono text-xs text-ink shadow-sm placeholder:text-slate-300 focus:border-brand-600 focus:outline-none focus:ring-4 focus:ring-brand-600/10"
          />
          <p className="mt-1.5 text-xs text-muted">{info.hint}</p>
          {fieldError("json") && (
            <p className="mt-1.5 text-xs font-medium text-red-600">
              {fieldError("json")}
            </p>
          )}
        </div>

        {general && <Alert>{general}</Alert>}
        {done && <Alert tone="info">{done}</Alert>}

        <Button type="submit" loading={busy} disabled={!json.trim()}>
          Import data
        </Button>
      </form>
    </Card>
  );
}
