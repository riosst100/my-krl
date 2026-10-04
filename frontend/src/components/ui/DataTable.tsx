import type { ReactNode } from "react";
import { cx } from "@/components/ui";

export interface Column<T> {
  key: string;
  /** Column heading; also the label of the value on mobile cards. */
  header: string;
  cell: (row: T) => ReactNode;
  /** Extra classes for the desktop cell (alignment, nowrap, colour). */
  className?: string;
  /**
   * Role on mobile cards: "title" = the card heading, "action" = the footer
   * row (links/buttons, no label), "hide" = desktop only. Default: a
   * label/value row.
   */
  mobile?: "title" | "action" | "hide";
}

/**
 * A table from `md` up and a list of cards on phones, so data-heavy admin
 * pages never need sideways scrolling to be read.
 */
export function DataTable<T>({
  columns,
  rows,
  rowKey,
  busy,
  caption,
}: {
  columns: Column<T>[];
  rows: T[];
  rowKey: (row: T) => string | number;
  /** Dims the rows while a new page/filter is loading. */
  busy?: boolean;
  caption?: string;
}) {
  const title = columns.find((c) => c.mobile === "title");
  const actions = columns.filter((c) => c.mobile === "action");
  const details = columns.filter((c) => !c.mobile);

  return (
    <div className={cx("transition-opacity", busy && "opacity-60")}>
      {/* Phones: cards */}
      <ul className="divide-y divide-line md:hidden">
        {rows.map((row) => (
          <li key={rowKey(row)} className="px-4 py-3.5">
            {title && (
              <div className="text-[15px] font-semibold leading-snug text-ink">
                {title.cell(row)}
              </div>
            )}
            <dl className={cx("space-y-1.5 text-[13px]", title && "mt-2")}>
              {details.map((c) => (
                <div
                  key={c.key}
                  className="flex items-start justify-between gap-4"
                >
                  <dt className="shrink-0 text-muted">{c.header}</dt>
                  <dd className="min-w-0 text-right text-slate-700">
                    {c.cell(row)}
                  </dd>
                </div>
              ))}
            </dl>
            {actions.length > 0 && (
              <div className="mt-3 flex flex-wrap items-center justify-end gap-3">
                {actions.map((c) => (
                  <span key={c.key}>{c.cell(row)}</span>
                ))}
              </div>
            )}
          </li>
        ))}
      </ul>

      {/* md and up: table */}
      <div className="hidden overflow-x-auto md:block">
        <table className="w-full text-left text-sm">
          {caption && <caption className="sr-only">{caption}</caption>}
          <thead className="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-muted">
            <tr>
              {columns.map((c) => (
                <th key={c.key} scope="col" className="px-4 py-3">
                  {c.mobile === "action" ? (
                    <span className="sr-only">{c.header}</span>
                  ) : (
                    c.header
                  )}
                </th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-line">
            {rows.map((row) => (
              <tr key={rowKey(row)} className="align-top">
                {columns.map((c) => (
                  <td key={c.key} className={cx("px-4 py-3", c.className)}>
                    {c.cell(row)}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
