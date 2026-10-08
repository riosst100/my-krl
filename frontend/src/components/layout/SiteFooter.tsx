import Link from "next/link";

export function SiteFooter() {
  return (
    <footer className="border-t border-line">
      {/* Bottom padding on phones keeps the content clear of the fixed tab bar. */}
      <div className="mx-auto max-w-5xl px-4 pt-6 pb-28 md:pb-6">
        <div role="note" className="flex items-start gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3.5 shadow-sm">
          <span className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-amber-400 text-amber-950">
            <svg viewBox="0 0 24 24" className="h-[18px] w-[18px]" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
              <path d="M12 3l9.5 17h-19z" />
              <path d="M12 10v4M12 17.5v.01" />
            </svg>
          </span>
          <div>
            <p className="text-sm font-bold text-amber-950">Perhatikan informasi di stasiun</p>
            <p className="mt-0.5 text-[13px] leading-relaxed text-amber-900">Jadwal dapat berubah sewaktu-waktu. Selalu cek pengumuman di stasiun.</p>
          </div>
        </div>

        <Link
          href="/download"
          className="mt-4 flex items-center justify-between gap-3 rounded-xl border border-line/80 bg-surface px-4 py-3 shadow-card transition-colors hover:border-brand-600/40"
        >
          <span className="flex items-center gap-3">
            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-brand-500 to-brand-700 text-[10px] font-extrabold text-white">
              KRL
            </span>
            <span>
              <span className="block text-sm font-bold text-ink">Aplikasi My KRL untuk Android</span>
              <span className="block text-xs text-muted">Rute favorit &amp; posisi kereta di HP Anda</span>
            </span>
          </span>
          <span className="shrink-0 text-sm font-semibold text-brand-600">Unduh →</span>
        </Link>

        <p className="mt-5 text-center text-[11px] text-muted sm:text-xs">© {new Date().getFullYear()} My KRL. All Rights Reserved.</p>
      </div>
    </footer>
  );
}
