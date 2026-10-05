"use client";

import { useEffect, useRef, useState, type FormEvent } from "react";
import { Alert, Button, SelectField } from "@/components/ui";
import { ApiError, errorMessage } from "@/lib/api/client";
import {
  MAX_FAVORITE_ROUTES,
  MIN_FAVORITE_ROUTES,
  type FavoriteRoute,
  type RouteInput,
} from "@/lib/api/favorites";
import type { Station } from "@/lib/api/types";

interface Props {
  open: boolean;
  /** Required = no route yet: the dialog cannot be dismissed until one route is chosen. */
  required: boolean;
  stations: Station[];
  initial: FavoriteRoute[];
  onSave: (routes: RouteInput[]) => Promise<void>;
  onClose: () => void;
}

const EMPTY: RouteInput = { from: "", to: "" };

const startRows = (initial: FavoriteRoute[]): RouteInput[] =>
  initial.length > 0
    ? initial.map((r) => ({ from: r.from.code, to: r.to.code }))
    : [{ ...EMPTY }];

/**
 * Asks for the visitor's favourite routes: 1 to 4 pairs of departure and
 * destination station. Uses the native <dialog> (focus stays inside, the
 * background is inert).
 */
export function FavoriteRoutesDialog({
  open,
  required,
  stations,
  initial,
  onSave,
  onClose,
}: Props) {
  const ref = useRef<HTMLDialogElement>(null);
  const [rows, setRows] = useState<RouteInput[]>(() => startRows(initial));
  const [error, setError] = useState<ApiError | string>();
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    const dialog = ref.current;
    if (!dialog) return;
    if (open && !dialog.open) {
      setRows(startRows(initial));
      setError(undefined);
      dialog.showModal();
    } else if (!open && dialog.open) {
      dialog.close();
    }
  }, [open, initial]);

  const update = (index: number, patch: Partial<RouteInput>) => {
    setRows((current) =>
      current.map((row, i) => (i === index ? { ...row, ...patch } : row)),
    );
    setError(undefined);
  };

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    if (rows.some((r) => !r.from || !r.to)) {
      setError("Pilih stasiun asal dan tujuan untuk setiap rute.");
      return;
    }
    if (rows.some((r) => r.from === r.to)) {
      setError("Stasiun asal dan tujuan harus berbeda.");
      return;
    }
    if (new Set(rows.map((r) => `${r.from}>${r.to}`)).size !== rows.length) {
      setError("Ada rute yang sama. Hapus salah satunya.");
      return;
    }

    setSaving(true);
    setError(undefined);
    try {
      await onSave(rows);
      onClose();
    } catch (err) {
      setError(
        err instanceof ApiError && err.isValidation ? err : errorMessage(err),
      );
    } finally {
      setSaving(false);
    }
  };

  const fieldError = (index: number, key: "from" | "to") =>
    error instanceof ApiError
      ? error.field(`routes.${index}.${key}`)
      : undefined;
  const message =
    typeof error === "string"
      ? error
      : error instanceof ApiError
        ? (error.field("routes") ?? error.message)
        : undefined;

  return (
    <dialog
      ref={ref}
      aria-labelledby="favorite-dialog-title"
      aria-describedby="favorite-dialog-desc"
      className="m-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-lg overflow-y-auto overscroll-contain rounded-none border border-line bg-white p-0 text-ink shadow-xl backdrop:bg-ink/60 backdrop:backdrop-blur-sm"
      onCancel={(e) => {
        // Esc closes only when the choice is not mandatory.
        e.preventDefault();
        if (!required) onClose();
      }}
    >
      <form onSubmit={submit} className="p-5 sm:p-7" noValidate>
        <h2
          id="favorite-dialog-title"
          className="text-lg font-bold tracking-tight sm:text-xl"
        >
          {required ? "Pilih rute favorit" : "Rute favorit"}
        </h2>
        <p
          id="favorite-dialog-desc"
          className="mt-1 text-[13px] leading-relaxed text-muted sm:text-sm"
        >
          Pilih minimal {MIN_FAVORITE_ROUTES} rute (stasiun asal → tujuan), bisa
          sampai {MAX_FAVORITE_ROUTES} rute. Kereta berikutnya untuk tiap rute
          tampil langsung di beranda.
        </p>

        <div className="mt-5 space-y-4">
          {rows.map((row, index) => (
            <fieldset
              key={index}
              className="rounded-none border border-line bg-slate-50/50 p-4"
            >
              <legend className="px-1 text-[13px] font-semibold text-ink">
                Rute {index + 1}
              </legend>
              <div className="space-y-3">
                <SelectField
                  label="Dari stasiun"
                  value={row.from}
                  autoFocus={index === 0}
                  required
                  error={fieldError(index, "from")}
                  onChange={(e) => update(index, { from: e.target.value })}
                >
                  <option value="">Pilih stasiun asal…</option>
                  {stations.map((s) => (
                    <option key={s.code} value={s.code}>
                      {s.name} ({s.code})
                    </option>
                  ))}
                </SelectField>
                <SelectField
                  label="Ke stasiun"
                  value={row.to}
                  required
                  error={fieldError(index, "to")}
                  onChange={(e) => update(index, { to: e.target.value })}
                >
                  <option value="">Pilih stasiun tujuan…</option>
                  {stations.map((s) => (
                    <option
                      key={s.code}
                      value={s.code}
                      disabled={s.code === row.from}
                    >
                      {s.name} ({s.code})
                    </option>
                  ))}
                </SelectField>
              </div>
              {rows.length > MIN_FAVORITE_ROUTES && (
                <button
                  type="button"
                  onClick={() =>
                    setRows((current) => current.filter((_, i) => i !== index))
                  }
                  className="mt-3 text-[13px] font-semibold text-red-700 hover:underline"
                >
                  Hapus rute {index + 1}
                </button>
              )}
            </fieldset>
          ))}
        </div>

        {rows.length < MAX_FAVORITE_ROUTES && (
          <button
            type="button"
            onClick={() => setRows((current) => [...current, { ...EMPTY }])}
            className="mt-4 text-sm font-semibold text-brand-600 hover:underline"
          >
            + Tambah rute ({rows.length}/{MAX_FAVORITE_ROUTES})
          </button>
        )}

        {message && (
          <div className="mt-4">
            <Alert>{message}</Alert>
          </div>
        )}

        <div className="mt-6 flex justify-end gap-2">
          {!required && (
            <Button
              type="button"
              variant="ghost"
              onClick={onClose}
              disabled={saving}
            >
              Batal
            </Button>
          )}
          <Button
            type="submit"
            loading={saving}
            className={required ? "w-full" : undefined}
          >
            Simpan rute favorit
          </Button>
        </div>
      </form>
    </dialog>
  );
}
