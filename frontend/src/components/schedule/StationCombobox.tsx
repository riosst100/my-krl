"use client";

import { useEffect, useId, useMemo, useRef, useState, type KeyboardEvent } from "react";
import { controlClass, cx } from "@/components/ui";
import type { Station } from "@/lib/api/types";

interface Option {
  code: string;
  name: string;
}

interface Props {
  label: string;
  stations: Station[];
  /** Selected station code, "" = all stations. */
  value: string;
  onChange: (code: string) => void;
  /** Label of the "no station" option. */
  allLabel?: string;
}

const normalize = (text: string) => text.toLowerCase().replace(/[^a-z0-9]/g, "");

/**
 * Searchable station picker (ARIA combobox + listbox). Type a name or code
 * ("tanah", "THB") to filter; ↑/↓ to move, Enter to choose, Esc to close.
 */
export function StationCombobox({ label, stations, value, onChange, allLabel = "Semua stasiun" }: Props) {
  const id = useId();
  const listId = `${id}-list`;
  const rootRef = useRef<HTMLDivElement>(null);
  const listRef = useRef<HTMLUListElement>(null);
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const [active, setActive] = useState(0);

  const selected = stations.find((s) => s.code === value);

  const options = useMemo<Option[]>(() => {
    const q = normalize(query);
    if (!q) return [{ code: "", name: allLabel }, ...stations];

    const rank = (s: Station) => {
      const code = s.code.toLowerCase();
      const name = normalize(s.name);
      if (code === q) return 0;
      if (name.startsWith(q)) return 1;
      if (code.startsWith(q)) return 2;
      return 3;
    };
    return stations
      .filter((s) => normalize(s.name).includes(q) || s.code.toLowerCase().startsWith(q))
      .sort((a, b) => rank(a) - rank(b) || a.name.localeCompare(b.name));
  }, [query, stations, allLabel]);

  // Close when clicking outside.
  useEffect(() => {
    if (!open) return;
    const onPointerDown = (event: PointerEvent) => {
      if (!rootRef.current?.contains(event.target as Node)) {
        setOpen(false);
        setQuery("");
      }
    };
    document.addEventListener("pointerdown", onPointerDown);
    return () => document.removeEventListener("pointerdown", onPointerDown);
  }, [open]);

  // Keep the highlighted option visible.
  useEffect(() => {
    if (open) listRef.current?.querySelector<HTMLElement>(`[data-index="${active}"]`)?.scrollIntoView({ block: "nearest" });
  }, [active, open]);

  const openList = () => {
    setOpen(true);
    setQuery("");
    setActive(Math.max(0, value ? stations.findIndex((s) => s.code === value) + 1 : 0));
  };

  const choose = (code: string) => {
    onChange(code);
    setOpen(false);
    setQuery("");
  };

  const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
    if (event.key === "ArrowDown" || event.key === "ArrowUp") {
      event.preventDefault();
      if (!open) return openList();
      const step = event.key === "ArrowDown" ? 1 : -1;
      setActive((i) => Math.min(options.length - 1, Math.max(0, i + step)));
    } else if (event.key === "Enter" && open) {
      event.preventDefault();
      const option = options[active];
      if (option) choose(option.code);
    } else if (event.key === "Escape" && open) {
      event.preventDefault();
      setOpen(false);
      setQuery("");
    } else if (event.key === "Tab") {
      setOpen(false);
      setQuery("");
    }
  };

  const displayValue = open ? query : selected ? `${selected.name} (${selected.code})` : "";

  return (
    <div ref={rootRef} className="relative">
      <label htmlFor={id} className="mb-1.5 block text-[13px] font-semibold text-slate-700">
        {label}
      </label>
      <div className="relative">
        <svg
          className="pointer-events-none absolute top-1/2 left-3.5 h-4 w-4 -translate-y-1/2 text-slate-400"
          viewBox="0 0 20 20"
          fill="currentColor"
          aria-hidden="true"
        >
          <path
            fillRule="evenodd"
            d="M9 3.5a5.5 5.5 0 1 0 3.47 9.77l3.13 3.13a.75.75 0 1 0 1.06-1.06l-3.13-3.13A5.5 5.5 0 0 0 9 3.5ZM5 9a4 4 0 1 1 8 0 4 4 0 0 1-8 0Z"
            clipRule="evenodd"
          />
        </svg>
        <input
          id={id}
          type="text"
          role="combobox"
          aria-expanded={open}
          aria-controls={listId}
          aria-autocomplete="list"
          aria-activedescendant={open && options[active] ? `${id}-opt-${active}` : undefined}
          autoComplete="off"
          spellCheck={false}
          placeholder={open ? "Ketik nama atau kode stasiun…" : allLabel}
          value={displayValue}
          onFocus={openList}
          onClick={() => !open && openList()}
          onChange={(e) => {
            setQuery(e.target.value);
            setOpen(true);
            setActive(0);
          }}
          onKeyDown={onKeyDown}
          className={cx(controlClass, "h-11 border-line pr-10 pl-10 placeholder:text-ink", open && "placeholder:text-slate-400")}
        />
        {value && !open ? (
          <button
            type="button"
            onClick={() => choose("")}
            aria-label="Hapus pilihan stasiun"
            className="absolute top-1/2 right-2 flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-ink"
          >
            <span aria-hidden="true">✕</span>
          </button>
        ) : (
          <svg
            className="pointer-events-none absolute top-1/2 right-3.5 h-4 w-4 -translate-y-1/2 text-slate-400"
            viewBox="0 0 20 20"
            fill="currentColor"
            aria-hidden="true"
          >
            <path
              fillRule="evenodd"
              d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z"
              clipRule="evenodd"
            />
          </svg>
        )}
      </div>

      {open && (
        <ul
          ref={listRef}
          id={listId}
          role="listbox"
          aria-label={label}
          className="absolute z-30 mt-1.5 max-h-72 w-full overflow-auto rounded-xl border border-line bg-white py-1 text-sm shadow-lg"
        >
          {options.length === 0 ? (
            <li className="px-3.5 py-2.5 text-muted">Stasiun tidak ditemukan</li>
          ) : (
            options.map((option, index) => {
              const isSelected = option.code === value;
              return (
                <li
                  key={option.code || "all"}
                  id={`${id}-opt-${index}`}
                  data-index={index}
                  role="option"
                  aria-selected={isSelected}
                  onPointerDown={(e) => e.preventDefault()}
                  onClick={() => choose(option.code)}
                  onPointerMove={() => setActive(index)}
                  className={cx(
                    "flex cursor-pointer items-center justify-between gap-3 px-3.5 py-2.5",
                    index === active && "bg-brand-50",
                    isSelected && "font-semibold text-brand-700",
                  )}
                >
                  <span className="truncate">{option.name}</span>
                  {option.code && <span className="shrink-0 text-xs font-semibold text-muted">{option.code}</span>}
                </li>
              );
            })
          )}
        </ul>
      )}
    </div>
  );
}
