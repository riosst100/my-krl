"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import type { ReactNode } from "react";
import { useAuth } from "@/lib/auth/AuthProvider";
import { cx, Skeleton } from "@/components/ui";

const NAV = [
  { href: "/schedule", label: "Jadwal" },
  { href: "/stations", label: "Stasiun" },
];

export function Logo() {
  return (
    <Link href="/" className="flex items-center gap-2.5 font-bold tracking-tight text-ink">
      <span
        className="flex h-8 w-8 items-center justify-center rounded-lg bg-gradient-to-br from-brand-500 to-brand-700 text-[11px] font-extrabold text-white shadow-sm shadow-brand-600/30"
        aria-hidden="true"
      >
        KRL
      </span>
      <span className="text-[15px]">My KRL</span>
    </Link>
  );
}

function isActive(pathname: string, href: string) {
  return href === "/" ? pathname === "/" : pathname.startsWith(href);
}

export function SiteHeader() {
  const pathname = usePathname();
  const { user, status } = useAuth();

  const linkClass = (href: string) =>
    cx(
      "rounded-lg px-3 py-2 text-sm font-semibold transition-colors",
      isActive(pathname, href) ? "bg-brand-50 text-brand-700" : "text-slate-600 hover:bg-slate-100 hover:text-ink",
    );

  const signedIn = status === "authenticated" && !!user;

  return (
    <>
      <header className="sticky top-0 z-30 border-b border-line/80 bg-white/85 backdrop-blur-md">
        <div className="mx-auto flex h-14 max-w-5xl items-center gap-2 px-4 sm:h-16">
          <Logo />

          {/* Desktop navigation */}
          <nav className="ml-auto hidden items-center gap-1 md:flex" aria-label="Navigasi utama">
            {NAV.map((item) => (
              <Link key={item.href} href={item.href} className={linkClass(item.href)}>
                {item.label}
              </Link>
            ))}

            <span className="mx-1 h-6 w-px bg-line" aria-hidden="true" />

            {status === "loading" ? (
              <Skeleton className="h-9 w-20" />
            ) : signedIn ? (
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
                  className="rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm shadow-brand-600/20 transition-colors hover:bg-brand-700"
                >
                  Daftar
                </Link>
              </>
            )}
          </nav>

          {/* Mobile: a single compact action; main navigation lives in the bottom bar. */}
          <div className="ml-auto md:hidden">
            {status === "loading" ? (
              <Skeleton className="h-8 w-16" />
            ) : signedIn ? (
              <Link
                href="/account"
                aria-label="Akun"
                title={user.email}
                className="flex h-8 w-8 items-center justify-center rounded-full bg-brand-600 text-sm font-bold text-white"
              >
                {user.name.charAt(0).toUpperCase()}
              </Link>
            ) : (
              <Link
                href="/register"
                className="rounded-lg bg-brand-600 px-3 py-1.5 text-[13px] font-semibold text-white shadow-sm shadow-brand-600/20 active:bg-brand-700"
              >
                Daftar
              </Link>
            )}
          </div>
        </div>
      </header>

      <MobileTabBar pathname={pathname} signedIn={signedIn} />
    </>
  );
}

function MobileTabBar({ pathname, signedIn }: { pathname: string; signedIn: boolean }) {
  const accountHref = signedIn ? "/account" : "/login";
  const tabs: { href: string; label: string; icon: ReactNode; active: boolean }[] = [
    { href: "/", label: "Beranda", icon: <HomeIcon />, active: isActive(pathname, "/") },
    { href: "/schedule", label: "Jadwal", icon: <ClockIcon />, active: isActive(pathname, "/schedule") },
    { href: "/stations", label: "Stasiun", icon: <PinIcon />, active: isActive(pathname, "/stations") },
    {
      href: accountHref,
      label: signedIn ? "Akun" : "Masuk",
      icon: <UserIcon />,
      active: ["/account", "/login", "/register"].some((p) => pathname.startsWith(p)),
    },
  ];

  return (
    <nav
      aria-label="Navigasi utama"
      className="pb-safe fixed inset-x-0 bottom-0 z-30 border-t border-line/80 bg-white/90 backdrop-blur-md md:hidden"
    >
      <div className="mx-auto grid h-16 max-w-md grid-cols-4">
        {tabs.map((tab) => (
          <Link
            key={tab.label}
            href={tab.href}
            aria-current={tab.active ? "page" : undefined}
            className={cx(
              "flex flex-col items-center justify-center gap-1 text-[11px] font-semibold transition-colors",
              tab.active ? "text-brand-600" : "text-slate-500 active:text-ink",
            )}
          >
            <span className={cx("flex h-7 w-12 items-center justify-center rounded-full transition-colors", tab.active && "bg-brand-50")}>
              {tab.icon}
            </span>
            {tab.label}
          </Link>
        ))}
      </div>
    </nav>
  );
}

// --- Icons (outline, 20px) ----------------------------------------------------

function Icon({ children }: { children: ReactNode }) {
  return (
    <svg viewBox="0 0 24 24" className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      {children}
    </svg>
  );
}

function HomeIcon() {
  return (
    <Icon>
      <path d="M3 10.5 12 3l9 7.5" />
      <path d="M5 9.5V20a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V9.5" />
    </Icon>
  );
}

function ClockIcon() {
  return (
    <Icon>
      <circle cx="12" cy="12" r="9" />
      <path d="M12 7v5l3 2" />
    </Icon>
  );
}

function PinIcon() {
  return (
    <Icon>
      <path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21Z" />
      <circle cx="12" cy="9.5" r="2.5" />
    </Icon>
  );
}

function UserIcon() {
  return (
    <Icon>
      <circle cx="12" cy="8" r="4" />
      <path d="M4 21a8 8 0 0 1 16 0" />
    </Icon>
  );
}
