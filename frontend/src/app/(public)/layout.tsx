import { SiteHeader } from "@/components/layout/SiteHeader";
import { AuthProvider } from "@/lib/auth/AuthProvider";

export default function PublicLayout({ children }: LayoutProps<"/">) {
  return (
    <AuthProvider>
      <SiteHeader />
      <main className="mx-auto w-full max-w-5xl flex-1 px-4 py-6 sm:py-10">{children}</main>
      <footer className="border-t border-line bg-white">
        <div className="mx-auto max-w-5xl px-4 py-6 text-xs leading-relaxed text-muted">
          Jadwal bersumber dari data KAI Commuter dan disinkronkan setiap hari. Jadwal dapat berubah sewaktu-waktu — selalu
          perhatikan informasi di stasiun.
        </div>
      </footer>
    </AuthProvider>
  );
}
