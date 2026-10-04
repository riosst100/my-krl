import type { Metadata } from "next";
import { AdminAuthProvider } from "@/lib/auth/AdminAuthProvider";

export const metadata: Metadata = {
  title: { default: "Admin", template: "%s · Admin My KRL" },
  robots: { index: false, follow: false },
};

export default function AdminRootLayout({ children }: LayoutProps<"/admin">) {
  return <AdminAuthProvider>{children}</AdminAuthProvider>;
}
