"use client";

import { useState, type FormEvent, type ReactNode } from "react";
import {
  SyncStatusBadge,
  TRIGGER_LABELS,
} from "@/components/admin/SyncStatusBadge";
import {
  Alert,
  Badge,
  Button,
  Card,
  Skeleton,
  TextField,
} from "@/components/ui";
import { useToast } from "@/components/ui/Toast";
import { ApiError, errorMessage } from "@/lib/api/client";
import type { SyncLog } from "@/lib/api/types";
import { formatDateTime, formatDateTimeLong } from "@/lib/format";
import { useApi } from "@/lib/hooks/useApi";

export interface ApiUrlSetting {
  url: string;
  default_url: string;
  is_default: boolean;
  updated_at: string | null;
  updated_by: string | null;
}

export interface ApiPreview {
  ok: boolean;
  error: string | null;
}

interface Props<P extends ApiPreview> {
  /** e.g. "Sumber data stasiun" */
  title: string;
  /** e.g. "Stations API URL" */
  urlLabel: string;
  urlHint: string;
  /** Shown when the saved URL is empty (KCI client fallback). */
  emptyUrlText: string;
  settingKey: string;
  getSetting: () => Promise<ApiUrlSetting>;
  saveUrl: (url: string) => Promise<ApiUrlSetting>;
  resetUrl: () => Promise<ApiUrlSetting>;
  testUrl: (url: string) => Promise<P>;
  renderPreview: (preview: P) => ReactNode;

  /** Sync section; omit for a URL-only card (e.g. synced together with another job). */
  sync?: {
    last: SyncLog | null | undefined;
    lastSuccess: SyncLog | null | undefined;
    inProgress: boolean;
    next: string | undefined;
    /** e.g. "Sync otomatis setiap bulan" */
    scheduleText: string;
    /** Summary line for a finished sync, e.g. "111 stasiun · baru 0 · berubah 0" */
    summary?: (log: SyncLog) => ReactNode;
    syncLabel: string;
    startedMessage: string;
    conflictMessage: string;
    triggerSync: () => Promise<unknown>;
    /** Reload the page data (sync meta). */
    onChanged: () => void;
  };
  /** Extra note under the URL (e.g. "Disinkronkan bersama jadwal"). */
  note?: ReactNode;
}

/**
 * Admin card for an API-backed sync: where the data comes from (editable URL
 * with a dry-run "Tes URL"), when it was last synced, and a "sync now" button.
 */
export function ApiSyncPanel<P extends ApiPreview>(props: Props<P>) {
  const { toast } = useToast();
  const setting = useApi(props.settingKey, () => props.getSetting());
  const [editing, setEditing] = useState(false);
  const [syncing, setSyncing] = useState(false);
  const sync = props.sync;

  const syncNow = async () => {
    if (!sync) return;
    setSyncing(true);
    try {
      await sync.triggerSync();
      toast(sync.startedMessage, "success");
      sync.onChanged();
    } catch (err) {
      toast(
        err instanceof ApiError && err.status === 409
          ? sync.conflictMessage
          : errorMessage(err),
        "error",
      );
    } finally {
      setSyncing(false);
    }
  };

  const sourceId = `${props.settingKey}-source`;
  const syncId = `${props.settingKey}-sync`;

  return (
    <Card className="mb-6 divide-y divide-line">
      <section className="p-5" aria-labelledby={sourceId}>
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0 flex-1">
            <h2 id={sourceId} className="font-semibold text-ink">
              {props.title} ({props.urlLabel})
            </h2>
            {setting.loading && !setting.data ? (
              <Skeleton className="mt-2 h-5 w-80" />
            ) : setting.data ? (
              <>
                <p className="mt-1 break-all font-mono text-sm text-ink">
                  {setting.data.url || (
                    <span className="font-sans text-muted">
                      {props.emptyUrlText}
                    </span>
                  )}
                </p>
                <p className="mt-1 text-xs text-muted">
                  {setting.data.is_default ? (
                    <Badge>Default</Badge>
                  ) : (
                    <>
                      Diubah{" "}
                      {setting.data.updated_by
                        ? `oleh ${setting.data.updated_by} `
                        : ""}
                      pada {formatDateTime(setting.data.updated_at)}
                    </>
                  )}
                </p>
              </>
            ) : (
              <p className="mt-1 text-sm text-red-700">
                Pengaturan tidak dapat dimuat.
              </p>
            )}
            {props.note && (
              <p className="mt-2 text-xs text-muted">{props.note}</p>
            )}
          </div>
          {!editing && setting.data && (
            <Button
              variant="secondary"
              size="sm"
              onClick={() => setEditing(true)}
            >
              Ubah URL
            </Button>
          )}
        </div>

        {editing && setting.data && (
          <UrlForm
            label={props.urlLabel}
            hint={props.urlHint}
            initialUrl={setting.data.url}
            defaultUrl={setting.data.default_url}
            isDefault={setting.data.is_default}
            saveUrl={props.saveUrl}
            resetUrl={props.resetUrl}
            testUrl={props.testUrl}
            renderPreview={props.renderPreview}
            onDone={() => {
              setEditing(false);
              setting.reload();
            }}
          />
        )}
      </section>

      {sync && (
        <section
          className="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between"
          aria-labelledby={syncId}
        >
          <div>
            <div className="flex flex-wrap items-center gap-2">
              <h2 id={syncId} className="font-semibold text-ink">
                Terakhir sync
              </h2>
              {sync.last && <SyncStatusBadge status={sync.last.status} />}
            </div>
            {sync.last ? (
              <>
                <p className="mt-1 text-sm text-ink">
                  <time
                    dateTime={
                      sync.last.finished_at ??
                      sync.last.started_at ??
                      sync.last.created_at ??
                      undefined
                    }
                  >
                    {formatDateTimeLong(
                      sync.last.finished_at ??
                        sync.last.started_at ??
                        sync.last.created_at,
                    )}
                  </time>
                  <span className="text-muted">
                    {" "}
                    · {TRIGGER_LABELS[sync.last.trigger] ?? sync.last.trigger}
                    {sync.last.triggered_by
                      ? ` oleh ${sync.last.triggered_by.name}`
                      : ""}
                    {sync.last.source ? ` · sumber: ${sync.last.source}` : ""}
                  </span>
                </p>
                {(sync.last.status === "success" ||
                  sync.last.status === "partial") &&
                  sync.summary && (
                    <p className="mt-1 text-sm text-muted">
                      {sync.summary(sync.last)}
                    </p>
                  )}
                {sync.last.error_message && (
                  <p className="mt-1 break-all text-sm text-red-700">
                    {sync.last.error_message}
                  </p>
                )}
                {sync.last.status !== "success" &&
                  sync.last.status !== "queued" &&
                  sync.last.status !== "running" && (
                    <p className="mt-1 text-sm text-muted">
                      Terakhir berhasil:{" "}
                      {sync.lastSuccess
                        ? formatDateTimeLong(sync.lastSuccess.finished_at)
                        : "belum pernah"}
                    </p>
                  )}
              </>
            ) : (
              <p className="mt-1 text-sm text-muted">
                Belum pernah disinkronkan.
              </p>
            )}
            <p className="mt-2 text-xs text-muted">
              {sync.scheduleText} · berikutnya{" "}
              {sync.next ? formatDateTimeLong(sync.next) : "—"}
            </p>
          </div>
          <Button
            onClick={syncNow}
            loading={syncing}
            disabled={sync.inProgress}
            className="shrink-0"
          >
            {sync.inProgress ? "Sinkronisasi berjalan…" : sync.syncLabel}
          </Button>
        </section>
      )}
    </Card>
  );
}

function UrlForm<P extends ApiPreview>({
  label,
  hint,
  initialUrl,
  defaultUrl,
  isDefault,
  saveUrl,
  resetUrl,
  testUrl,
  renderPreview,
  onDone,
}: {
  label: string;
  hint: string;
  initialUrl: string;
  defaultUrl: string;
  isDefault: boolean;
  saveUrl: (url: string) => Promise<ApiUrlSetting>;
  resetUrl: () => Promise<ApiUrlSetting>;
  testUrl: (url: string) => Promise<P>;
  renderPreview: (preview: P) => ReactNode;
  onDone: () => void;
}) {
  const { toast } = useToast();
  const [url, setUrl] = useState(initialUrl);
  const [error, setError] = useState<string>();
  const [preview, setPreview] = useState<P | null>(null);
  const [busy, setBusy] = useState<"test" | "save" | "reset" | null>(null);

  const fieldError = (err: unknown) =>
    err instanceof ApiError && err.isValidation
      ? (err.field("url") ?? err.message)
      : errorMessage(err);

  const test = async () => {
    if (!url.trim()) {
      setError("Isi URL terlebih dahulu.");
      return;
    }
    setBusy("test");
    setError(undefined);
    setPreview(null);
    try {
      setPreview(await testUrl(url.trim()));
    } catch (err) {
      setError(fieldError(err));
    } finally {
      setBusy(null);
    }
  };

  const save = async (event: FormEvent) => {
    event.preventDefault();
    setBusy("save");
    setError(undefined);
    try {
      await saveUrl(url.trim());
      toast(`${label} disimpan. Berlaku untuk sync berikutnya.`, "success");
      onDone();
    } catch (err) {
      setError(fieldError(err));
    } finally {
      setBusy(null);
    }
  };

  const reset = async () => {
    setBusy("reset");
    try {
      await resetUrl();
      toast(`${label} dikembalikan ke default.`, "success");
      onDone();
    } catch (err) {
      toast(errorMessage(err), "error");
    } finally {
      setBusy(null);
    }
  };

  return (
    <form onSubmit={save} className="mt-4 space-y-3" noValidate>
      <TextField
        label={label}
        type="url"
        inputMode="url"
        placeholder={defaultUrl}
        value={url}
        onChange={(e) => {
          setUrl(e.target.value);
          setPreview(null);
        }}
        error={error}
        hint={hint}
      />

      {preview &&
        (preview.ok ? (
          <Alert tone="info">{renderPreview(preview)}</Alert>
        ) : (
          <Alert>
            <p className="font-semibold">URL tidak dapat dipakai.</p>
            <p className="mt-1 break-all">{preview.error}</p>
          </Alert>
        ))}

      <div className="flex flex-wrap gap-2">
        <Button
          type="button"
          variant="secondary"
          onClick={test}
          loading={busy === "test"}
          disabled={busy !== null && busy !== "test"}
        >
          Tes URL
        </Button>
        <Button
          type="submit"
          loading={busy === "save"}
          disabled={busy !== null && busy !== "save"}
        >
          Simpan
        </Button>
        <Button
          type="button"
          variant="ghost"
          onClick={onDone}
          disabled={busy !== null}
        >
          Batal
        </Button>
        {!isDefault && (
          <Button
            type="button"
            variant="ghost"
            onClick={reset}
            loading={busy === "reset"}
            disabled={busy !== null && busy !== "reset"}
            className="ml-auto"
          >
            Kembalikan ke default
          </Button>
        )}
      </div>
    </form>
  );
}
