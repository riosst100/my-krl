"use client";

import { ApiSyncPanel } from "@/components/admin/ApiSyncPanel";
import {
  getStationsApiSetting,
  resetStationsApiUrl,
  saveStationsApiUrl,
  testStationsApiUrl,
} from "@/lib/api/admin";
import { formatNumber } from "@/lib/format";

/** Admin → Stasiun: the Stations API URL the local machine reads station data from. */
export function StationSyncPanel() {
  return (
    <>
      <ApiSyncPanel
        title="Sumber data stasiun"
        urlLabel="Stations API URL"
        urlHint="Kosongkan untuk mengambil daftar stasiun dari klien KCI (mock/HTTP). Format JSON: array, atau array di dalam “data” / “stations”."
        emptyUrlText="Tidak memakai URL — daftar stasiun diambil dari klien KCI (mock/HTTP)"
        settingKey="stations-api"
        getSetting={getStationsApiSetting}
        saveUrl={saveStationsApiUrl}
        resetUrl={resetStationsApiUrl}
        testUrl={testStationsApiUrl}
        renderPreview={(p) => (
          <>
            <p className="font-semibold">
              URL dapat dibaca: {formatNumber(p.count)} stasiun ditemukan.
            </p>
            <p className="mt-1">
              Contoh: {p.sample.map((s) => `${s.name} (${s.code})`).join(", ")}
            </p>
          </>
        )}
      />
    </>
  );
}
