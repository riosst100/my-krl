"use client";

import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";
import { Button, SelectField } from "@/components/ui";
import type { Station } from "@/lib/api/types";

interface Props {
  stations: Station[];
  defaultStation?: string;
  /** Layout: stacked card (home) or a single row (schedule page). */
  layout?: "stacked" | "inline";
}

export function StationSearchForm({ stations, defaultStation = "", layout = "stacked" }: Props) {
  const router = useRouter();
  const [station, setStation] = useState(defaultStation);
  const [error, setError] = useState<string>();

  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (!station) {
      setError("Pilih stasiun terlebih dahulu.");
      return;
    }
    setError(undefined);
    const params = new URLSearchParams({ station });
    router.push(`/schedule?${params}`);
  };

  const inline = layout === "inline";

  return (
    // action/method + input names: the form also works before hydration (or without JS).
    <form action="/schedule" method="get" onSubmit={submit} noValidate className={inline ? "grid gap-4 sm:grid-cols-[1fr_auto] sm:items-end" : "space-y-4"}>
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

      <Button type="submit" size="lg" className={inline ? "w-full sm:w-auto" : "w-full"}>
        Cari Jadwal
      </Button>
    </form>
  );
}
