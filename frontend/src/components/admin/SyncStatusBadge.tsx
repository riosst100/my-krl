"use client";

import { Badge } from "@/components/ui";
import type { SyncStatus } from "@/lib/api/types";

const LABELS: Record<
  SyncStatus,
  { label: string; tone: "neutral" | "success" | "warning" | "danger" | "info" }
> = {
  queued: { label: "Antre", tone: "neutral" },
  running: { label: "Berjalan", tone: "info" },
  success: { label: "Berhasil", tone: "success" },
  partial: { label: "Sebagian", tone: "warning" },
  failed: { label: "Gagal", tone: "danger" },
};

export function SyncStatusBadge({ status }: { status: SyncStatus }) {
  const { label, tone } = LABELS[status] ?? LABELS.queued;
  return <Badge tone={tone}>{label}</Badge>;
}

export const SYNC_TYPE_LABELS: Record<string, string> = {
  kci_schedules: "Jadwal",
  kci_stations: "Stasiun",
  prod_push: "Sync ke Prod",
};

export const TRIGGER_LABELS: Record<string, string> = {
  schedule: "Terjadwal",
  manual: "Manual",
  console: "Konsol",
  push: "Sync ke Prod",
  ingest: "Dari lokal",
  import: "Import JSON",
};
