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
        className="mb-4 max-w-md sm:mb-6"
      />

      {filtered.length === 0 ? (
        <Card>
          <EmptyState title="Stasiun tidak ditemukan" description={`Tidak ada stasiun yang cocok dengan "${query}".`} />
        </Card>
      ) : (
        // Phones: one grouped list. Larger screens: a grid of cards.
        <ul className="divide-y divide-line overflow-hidden rounded-2xl border border-line/80 bg-white shadow-card sm:grid sm:grid-cols-2 sm:gap-3 sm:divide-y-0 sm:overflow-visible sm:rounded-none sm:border-0 sm:bg-transparent sm:shadow-none lg:grid-cols-3">
          {filtered.map((s) => (
            <li key={s.code}>
              <Link
                href={`/stations/${s.code}`}
                className="group flex items-center gap-3 px-4 py-3 transition-colors active:bg-slate-50 sm:rounded-xl sm:border sm:border-line/80 sm:bg-white sm:shadow-card sm:hover:border-brand-600"
              >
                <span className="min-w-0 flex-1 truncate text-sm font-semibold text-ink sm:text-[15px]">{s.name}</span>
                <span className="rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-bold tracking-wide text-slate-600">{s.code}</span>
                <span className="text-slate-300 transition-colors group-hover:text-brand-600" aria-hidden="true">
                  ›
                </span>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </>
  );
}
