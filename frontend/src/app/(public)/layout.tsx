import { SiteHeader } from "@/components/layout/SiteHeader";
import { AuthProvider } from "@/lib/auth/AuthProvider";

export default function PublicLayout({ children }: LayoutProps<"/">) {
  return (
    <AuthProvider>
      <SiteHeader />
      <main className="mx-auto w-full max-w-5xl flex-1 px-4 pt-5 pb-8 sm:pt-8 sm:pb-12">{children}</main>
      <footer className="border-t border-line bg-white">
        {/* Bottom padding on phones keeps the text clear of the fixed tab bar. */}
        <div className="mx-auto max-w-5xl px-4 pt-6 pb-28 text-xs leading-relaxed text-muted md:pb-6">
          Jadwal bersumber dari data KAI Commuter dan disinkronkan setiap hari. Jadwal dapat berubah sewaktu-waktu — selalu
          perhatikan informasi di stasiun.
        </div>
      </footer>
    </AuthProvider>
  );
}
