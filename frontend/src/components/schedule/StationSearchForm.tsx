"use client";

import { useRouter } from "next/navigation";
import { useEffect, useState, type FormEvent } from "react";
import { Button, SelectField, TextField } from "@/components/ui";
import { getAvailableDates } from "@/lib/api/schedules";
import type { Station } from "@/lib/api/types";
import { formatDateShort, todayInJakarta } from "@/lib/format";

interface Props {
  stations: Station[];
  defaultStation?: string;
  defaultDate?: string;
  /** Layout: stacked card (home) or a single row (schedule page). */
  layout?: "stacked" | "inline";
}

export function StationSearchForm({ stations, defaultStation = "", defaultDate, layout = "stacked" }: Props) {
  const router = useRouter();
  const [station, setStation] = useState(defaultStation);
  const [date, setDate] = useState(defaultDate ?? "");
  const [range, setRange] = useState<{ min?: string; max?: string }>({});
  const [error, setError] = useState<string>();

  useEffect(() => {
    // Default to "today" in Jakarta, computed on the client to avoid hydration drift.
    // eslint-disable-next-line react-hooks/set-state-in-effect -- client-only default
    if (!defaultDate) setDate(todayInJakarta());
    getAvailableDates()
      .then(({ dates }) => setRange({ min: dates[0], max: dates[dates.length - 1] }))
      .catch(() => setRange({}));
  }, [defaultDate]);

  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (!station) {
      setError("Pilih stasiun terlebih dahulu.");
      return;
    }
    setError(undefined);
    const params = new URLSearchParams({ station, date: date || todayInJakarta() });
    router.push(`/schedule?${params}`);
  };

  const inline = layout === "inline";

  return (
    // action/method + input names: the form also works before hydration (or without JS).
    <form action="/schedule" method="get" onSubmit={submit} noValidate className={inline ? "grid gap-4 sm:grid-cols-[1fr_200px_auto] sm:items-end" : "space-y-4"}>
      <SelectField
        label="Stasiun"
        name="station"
        value={station}
        onChange={(e) => {
          setStation(e.target.value);
          setError(undefined);
        }}
        error={error}
        required
      >
        <option value="">Pilih stasiun…</option>
        {stations.map((s) => (
          <option key={s.code} value={s.code}>
            {s.name} ({s.code})
          </option>
        ))}
      </SelectField>

      <TextField
        label="Tanggal"
        type="date"
        name="date"
        value={date}
        min={range.min}
        max={range.max}
        onChange={(e) => setDate(e.target.value)}
        hint={!inline && range.min && range.max ? `Jadwal tersedia ${formatDateShort(range.min)} – ${formatDateShort(range.max)}` : undefined}
        required
      />

      <Button type="submit" size="lg" className={inline ? "w-full sm:w-auto" : "w-full"}>
        Cari Jadwal
      </Button>
    </form>
  );
}
