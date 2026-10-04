import { SiteFooter } from "@/components/layout/SiteFooter";
import { SiteHeader } from "@/components/layout/SiteHeader";
import { AuthProvider } from "@/lib/auth/AuthProvider";

export default function PublicLayout({ children }: LayoutProps<"/">) {
  return (
    <AuthProvider>
      <a
        href="#main"
        className="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-50 focus:rounded-lg focus:bg-white focus:px-3 focus:py-2 focus:text-sm focus:font-semibold focus:text-ink focus:shadow-raised"
      >
        Lewati ke konten
      </a>
      <SiteHeader />
      <main
        id="main"
        className="mx-auto w-full max-w-5xl flex-1 px-4 pt-5 pb-8 sm:pt-8 sm:pb-12"
      >
        {children}
      </main>
      <SiteFooter />
    </AuthProvider>
  );
}
