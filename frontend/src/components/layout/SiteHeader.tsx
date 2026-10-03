"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useAuth } from "@/lib/auth/AuthProvider";
import { cx, Skeleton } from "@/components/ui";

const NAV = [
  { href: "/schedule", label: "Jadwal" },
  { href: "/stations", label: "Stasiun" },
];

export function Logo() {
  return (
    <Link href="/" className="flex items-center gap-2 font-bold tracking-tight text-ink">
      <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-600 text-sm text-white" aria-hidden="true">
        KRL
      </span>
      <span className="hidden sm:inline">Jadwal KRL</span>
    </Link>
  );
}

export function SiteHeader() {
  const pathname = usePathname();
  const { user, status } = useAuth();

  const linkClass = (href: string) =>
    cx(
      "rounded-lg px-3 py-2 text-sm font-semibold transition-colors",
      pathname.startsWith(href) ? "bg-brand-50 text-brand-700" : "text-slate-600 hover:bg-slate-100 hover:text-ink",
    );

  return (
    <header className="sticky top-0 z-30 border-b border-line bg-white/90 backdrop-blur">
      <div className="mx-auto flex h-16 max-w-5xl items-center gap-2 px-4">
        <Logo />
        <nav className="ml-auto flex items-center gap-1" aria-label="Navigasi utama">
          {NAV.map((item) => (
            <Link key={item.href} href={item.href} className={linkClass(item.href)}>
              {item.label}
            </Link>
          ))}

          <span className="mx-1 h-6 w-px bg-line" aria-hidden="true" />

          {status === "loading" ? (
            <Skeleton className="h-9 w-20" />
          ) : status === "authenticated" && user ? (
            <Link href="/account" className={linkClass("/account")} title={user.email}>
              Akun
            </Link>
          ) : (
            <>
              <Link href="/login" className={linkClass("/login")}>
                Masuk
              </Link>
              <Link
                href="/register"
                className="hidden rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-700 sm:inline-block"
              >
                Daftar
              </Link>
            </>
          )}
        </nav>
      </div>
    </header>
  );
}
