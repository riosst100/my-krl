"use client";

import { useState } from "react";
import { Alert, Badge, Button, Card, Skeleton } from "@/components/ui";
import { useToast } from "@/components/ui/Toast";
import { errorMessage } from "@/lib/api/client";
import { getSyncStationsSetting, setKrlSyncAutoSync } from "@/lib/api/admin";
import { useApi } from "@/lib/hooks/useApi";

/**
 * Admin → Configuration → Sync Configuration: krl-sync's daily automatic sync
 * (Vercel Cron) on/off. krl-sync reads it through GET /ingest/config.
 */
export function KrlSyncAutoPanel() {
  const { toast } = useToast();
  const setting = useApi("krl-sync-auto", () => getSyncStationsSetting());
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string>();
  const enabled = setting.data?.auto_sync;

  const toggle = async () => {
    setSaving(true);
    setError(undefined);
    try {
      await setKrlSyncAutoSync(!enabled);
      await setting.reload();
      toast(enabled ? "Sync otomatis dimatikan." : "Sync otomatis dinyalakan.", "success");
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Card className="mb-6 p-5">
      <div className="flex flex-wrap items-center gap-2">
        <h2 className="font-semibold text-ink">Sync otomatis (krl-sync di Vercel)</h2>
        {enabled !== undefined && (
          <Badge tone={enabled ? "success" : "neutral"}>{enabled ? "Aktif" : "Mati"}</Badge>
        )}
      </div>
      <p className="mt-1 text-sm text-muted">
        Server ini tidak mengambil data dari KCI. Jadwal dikirim oleh krl-sync di Vercel setiap hari pukul
        00.30 WIB untuk stasiun yang dipilih di bawah. Sync manual tetap bisa dijalankan dari halaman krl-sync
        walau sync otomatis dimatikan.
      </p>

      {setting.loading && !setting.data ? (
        <Skeleton className="mt-4 h-9 w-40" />
      ) : (
        <div className="mt-4 flex flex-wrap items-center gap-3">
          <Button variant={enabled ? "ghost" : "primary"} onClick={toggle} loading={saving}>
            {enabled ? "Matikan sync otomatis" : "Nyalakan sync otomatis"}
          </Button>
          <a
            href="https://krl-sync.vercel.app/"
            target="_blank"
            rel="noreferrer"
            className="text-sm font-semibold text-brand-600 hover:underline"
          >
            Buka krl-sync →
          </a>
        </div>
      )}

      {error && (
        <div className="mt-3">
          <Alert>{error}</Alert>
        </div>
      )}
    </Card>
  );
}
