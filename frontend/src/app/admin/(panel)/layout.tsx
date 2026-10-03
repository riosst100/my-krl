"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect } from "react";
import { cx, Spinner } from "@/components/ui";
import { useToast } from "@/components/ui/Toast";
import { useAdminAuth } from "@/lib/auth/AdminAuthProvider";

const NAV = [
  { href: "/admin/dashboard", label: "Dashboard" },
  { href: "/admin/users", label: "Pengguna" },
  { href: "/admin/stations", label: "Stasiun" },
  { href: "/admin/schedules", label: "Jadwal" },
  { href: "/admin/sync", label: "Sinkronisasi" },
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

  useEffect(() => {
    if (status === "guest") router.replace(`/admin/login?next=${encodeURIComponent(pathname)}`);
  }, [status, router, pathname]);

  if (status !== "authenticated" || !admin) {
    return (
      <div className="flex flex-1 items-center justify-center text-muted" role="status">
        <Spinner className="mr-2 h-5 w-5" /> Memeriksa sesi admin…
      </div>
    );
  }

  const onLogout = async () => {
    await logout();
    toast("Anda telah keluar dari panel admin.", "info");
    router.replace("/admin/login");
  };

  return (
    <div className="flex flex-1 flex-col lg:flex-row">
      <aside className="border-b border-slate-800 bg-ink text-slate-300 lg:sticky lg:top-0 lg:h-screen lg:w-60 lg:shrink-0 lg:border-b-0">
        <div className="flex h-14 items-center gap-2 px-4 lg:h-16 lg:px-5">
          <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-600 text-xs font-bold text-white">KRL</span>
          <span className="font-bold text-white">Admin</span>
        </div>
        <nav className="flex gap-1 overflow-x-auto px-2 pb-2 lg:flex-col lg:px-3 lg:pb-0" aria-label="Navigasi admin">
          {NAV.map((item) => (
            <Link
              key={item.href}
              href={item.href}
              className={cx(
                "whitespace-nowrap rounded-lg px-3 py-2 text-sm font-medium transition-colors",
                pathname.startsWith(item.href) ? "bg-white/10 text-white" : "hover:bg-white/5 hover:text-white",
              )}
            >
              {item.label}
            </Link>
          ))}
        </nav>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="flex h-14 items-center justify-end gap-3 border-b border-line bg-white px-4 lg:h-16 lg:px-8">
          <Link href="/" className="mr-auto text-sm text-muted hover:text-ink">
            ← Lihat situs
          </Link>
          <span className="hidden text-sm text-muted sm:inline">{admin.email}</span>
          <button type="button" onClick={onLogout} className="rounded-lg px-3 py-1.5 text-sm font-semibold text-ink hover:bg-slate-100">
            Keluar
          </button>
        </header>
        <main className="flex-1 px-4 py-6 lg:px-8 lg:py-8">{children}</main>
      </div>
    </div>
  );
}
