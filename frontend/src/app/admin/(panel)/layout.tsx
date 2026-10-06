"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { cx, Spinner } from "@/components/ui";
import { useToast } from "@/components/ui/Toast";
import { useAdminAuth } from "@/lib/auth/AdminAuthProvider";

interface NavItem {
  href: string;
  label: string;
  /** Sub menu: shown under the item while its section is open. */
  children?: { href: string; label: string }[];
}

const NAV: NavItem[] = [
  { href: "/admin/dashboard", label: "Dashboard" },
  { href: "/admin/users", label: "Pengguna" },
  { href: "/admin/stations", label: "Stasiun" },
  { href: "/admin/schedules", label: "Jadwal" },
  {
    href: "/admin/sync",
    label: "Sinkronisasi",
    children: [
      { href: "/admin/sync", label: "Sync Data" },
      { href: "/admin/sync/import", label: "Import Manual" },
      { href: "/admin/sync/sumber", label: "Sumber Data" },
      { href: "/admin/sync/riwayat", label: "Riwayat" },
    ],
  },
];

/**
 * Guard + shell for every admin page. Unauthenticated (or non-admin) visitors
 * are sent to /admin/login; the API independently rejects them as well.
 */
export default function AdminPanelLayout({ children }: LayoutProps<"/admin">) {
  const { admin, status, logout } = useAdminAuth();
  const router = useRouter();
  const pathname = usePathname();
  const { toast } = useToast();
  // The mobile menu is open "for" one path, so it closes by itself after navigating.
  const [openAt, setOpenAt] = useState<string | null>(null);
  const menuOpen = openAt === pathname;

  useEffect(() => {
    if (!menuOpen) return;
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && setOpenAt(null);
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [menuOpen]);

  useEffect(() => {
    if (status === "guest")
      router.replace(`/admin/login?next=${encodeURIComponent(pathname)}`);
  }, [status, router, pathname]);

  if (status !== "authenticated" || !admin) {
    return (
      <div
        className="flex flex-1 items-center justify-center text-muted"
        role="status"
      >
        <Spinner className="mr-2 h-5 w-5" /> Memeriksa sesi admin…
      </div>
    );
  }

  const onLogout = async () => {
    await logout();
    toast("Anda telah keluar dari panel admin.", "info");
    router.replace("/admin/login");
  };

  const itemClass = (active: boolean, sub = false) =>
    cx(
      "flex items-center rounded-lg text-sm transition-colors",
      sub
        ? "min-h-10 px-3 lg:min-h-9"
        : "min-h-11 px-3 font-medium lg:min-h-10",
      active ? "bg-white/10 text-white" : "hover:bg-white/5 hover:text-white",
    );

  const navLinks = (
    <ul className="space-y-1">
      {NAV.map((item) => {
        const open = pathname.startsWith(item.href);
        return (
          <li key={item.href}>
            <Link
              href={item.children ? item.children[0].href : item.href}
              aria-current={open && !item.children ? "page" : undefined}
              aria-expanded={item.children ? open : undefined}
              className={cx(
                itemClass(open && !item.children),
                open && item.children && "text-white",
              )}
            >
              {item.label}
            </Link>
            {item.children && open && (
              <ul className="ml-3 mt-1 space-y-0.5 border-l border-white/10 pl-2">
                {item.children.map((child) => {
                  // "/admin/sync" is the section's first page: only an exact match.
                  const active =
                    child.href === item.href
                      ? pathname === child.href
                      : pathname.startsWith(child.href);
                  return (
                    <li key={child.href}>
                      <Link
                        href={child.href}
                        aria-current={active ? "page" : undefined}
                        className={itemClass(active, true)}
                      >
                        {child.label}
                      </Link>
                    </li>
                  );
                })}
              </ul>
            )}
          </li>
        );
      })}
    </ul>
  );

  return (
    <div className="flex flex-1 flex-col lg:flex-row">
      <a
        href="#admin-main"
        className="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-50 focus:rounded-lg focus:bg-white focus:px-3 focus:py-2 focus:text-sm focus:font-semibold focus:text-ink focus:shadow-raised"
      >
        Lewati ke konten
      </a>

      {/* Phones and tablets: compact top bar + drawer */}
      <div className="sticky top-0 z-30 flex h-14 items-center gap-3 bg-ink px-3 text-white lg:hidden">
        <button
          type="button"
          onClick={() => setOpenAt(pathname)}
          aria-label="Buka menu"
          aria-expanded={menuOpen}
          aria-controls="admin-drawer"
          className="flex h-10 w-10 items-center justify-center rounded-lg hover:bg-white/10"
        >
          <svg
            viewBox="0 0 24 24"
            className="h-5 w-5"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            aria-hidden="true"
          >
            <path d="M4 7h16M4 12h16M4 17h16" />
          </svg>
        </button>
        <span className="flex h-7 w-7 items-center justify-center rounded-md bg-brand-600 text-[10px] font-bold">
          KRL
        </span>
        <span className="font-bold">Admin</span>
      </div>

      {menuOpen && (
        <div
          className="fixed inset-0 z-40 lg:hidden"
          id="admin-drawer"
          role="dialog"
          aria-modal="true"
          aria-label="Menu admin"
        >
          <button
            type="button"
            aria-label="Tutup menu"
            onClick={() => setOpenAt(null)}
            className="absolute inset-0 bg-ink/60 backdrop-blur-sm"
          />
          <div className="absolute inset-y-0 left-0 flex w-72 max-w-[85%] flex-col bg-ink text-slate-300 shadow-xl">
            <div className="flex h-14 items-center justify-between px-4">
              <span className="font-bold text-white">Menu admin</span>
              <button
                type="button"
                onClick={() => setOpenAt(null)}
                aria-label="Tutup menu"
                className="flex h-10 w-10 items-center justify-center rounded-lg hover:bg-white/10"
              >
                <svg
                  viewBox="0 0 24 24"
                  className="h-5 w-5"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="2"
                  strokeLinecap="round"
                  aria-hidden="true"
                >
                  <path d="M6 6l12 12M18 6L6 18" />
                </svg>
              </button>
            </div>
            <nav
              className="flex-1 overflow-y-auto px-3"
              aria-label="Navigasi admin"
            >
              {navLinks}
            </nav>
            <div className="space-y-1 border-t border-white/10 p-3">
              <p className="truncate px-3 pb-1 text-xs text-slate-400">
                {admin.email}
              </p>
              <Link
                href="/"
                className="flex min-h-11 items-center rounded-lg px-3 text-sm hover:bg-white/5 hover:text-white"
              >
                ← Lihat situs
              </Link>
              <button
                type="button"
                onClick={onLogout}
                className="flex min-h-11 w-full items-center rounded-lg px-3 text-sm font-semibold text-white hover:bg-white/5"
              >
                Keluar
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Desktop: sidebar */}
      <aside className="hidden bg-ink text-slate-300 lg:sticky lg:top-0 lg:flex lg:h-screen lg:w-60 lg:shrink-0 lg:flex-col">
        <div className="flex h-16 items-center gap-2 px-5">
          <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-600 text-xs font-bold text-white">
            KRL
          </span>
          <span className="font-bold text-white">Admin</span>
        </div>
        <nav className="px-3" aria-label="Navigasi admin">
          {navLinks}
        </nav>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="hidden h-16 items-center justify-end gap-3 border-b border-line bg-white px-8 lg:flex">
          <Link href="/" className="mr-auto text-sm text-muted hover:text-ink">
            ← Lihat situs
          </Link>
          <span className="text-sm text-muted">{admin.email}</span>
          <button
            type="button"
            onClick={onLogout}
            className="rounded-lg px-3 py-1.5 text-sm font-semibold text-ink hover:bg-slate-100"
          >
            Keluar
          </button>
        </header>
        <main
          id="admin-main"
          className="flex-1 px-4 py-5 sm:py-6 lg:px-8 lg:py-8"
        >
          {children}
        </main>
      </div>
    </div>
  );
}
