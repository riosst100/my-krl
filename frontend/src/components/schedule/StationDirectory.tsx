"use client";

import Link from "next/link";
import { useMemo, useState } from "react";
import { Card, EmptyState, TextField } from "@/components/ui";
import type { Station } from "@/lib/api/types";

export function StationDirectory({ stations }: { stations: Station[] }) {
  const [query, setQuery] = useState("");

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return stations;
    return stations.filter((s) => s.name.toLowerCase().includes(q) || s.code.toLowerCase().includes(q));
  }, [stations, query]);

  return (
    <>
      <TextField
        label="Cari stasiun"
        type="search"
        placeholder="Nama atau kode, mis. Manggarai / MRI"
        value={query}
        onChange={(e) => setQuery(e.target.value)}
        className="mb-6 max-w-md"
      />

      {filtered.length === 0 ? (
        <Card>
          <EmptyState title="Stasiun tidak ditemukan" description={`Tidak ada stasiun yang cocok dengan "${query}".`} />
        </Card>
      ) : (
        <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {filtered.map((s) => (
            <li key={s.code}>
              <Link
                href={`/stations/${s.code}`}
                className="flex items-center justify-between rounded-xl border border-line bg-white px-4 py-3 shadow-sm transition-colors hover:border-brand-600"
              >
                <span className="font-semibold text-ink">{s.name}</span>
                <span className="rounded-md bg-slate-100 px-2 py-0.5 text-xs font-bold text-slate-600">{s.code}</span>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </>
  );
}
