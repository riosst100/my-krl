"use client";

import { useRouter } from "next/navigation";
import { useEffect, useRef, useState } from "react";
import { Button, Card, Skeleton } from "@/components/ui";
import { useToast } from "@/components/ui/Toast";
import { useAuth } from "@/lib/auth/AuthProvider";
import { formatDateTime } from "@/lib/format";

export default function AccountPage() {
  const { user, status, logout } = useAuth();
  const router = useRouter();
  const { toast } = useToast();
  const [loggingOut, setLoggingOut] = useState(false);
  // Set on an intentional logout so the guest redirect below sends the user home, not to /login.
  const leaving = useRef(false);

  useEffect(() => {
    if (status === "guest") router.replace(leaving.current ? "/" : "/login?next=/account");
  }, [status, router]);

  if (status !== "authenticated" || !user) {
    return (
      <Card className="mx-auto max-w-2xl p-5 sm:p-6">
        <Skeleton className="h-7 w-40" />
        <Skeleton className="mt-6 h-5 w-full" />
        <Skeleton className="mt-3 h-5 w-2/3" />
      </Card>
    );
  }

  const onLogout = async () => {
    setLoggingOut(true);
    leaving.current = true;
    try {
      await logout();
      toast("Anda telah keluar.", "info");
    } finally {
      setLoggingOut(false);
    }
  };

  return (
    <div className="mx-auto max-w-2xl space-y-4 sm:space-y-6">
      <Card className="p-5 sm:p-8">
        <div className="flex items-center gap-4">
          <div
            className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-brand-500 to-brand-700 text-lg font-bold text-white shadow-sm shadow-brand-600/30 sm:h-14 sm:w-14 sm:text-xl"
            aria-hidden="true"
          >
            {user.name.charAt(0).toUpperCase()}
          </div>
          <div className="min-w-0">
            <h1 className="truncate text-lg font-bold tracking-tight text-ink sm:text-2xl">{user.name}</h1>
            <p className="truncate text-[13px] text-muted sm:text-sm">{user.email}</p>
          </div>
        </div>

        <dl className="mt-6 grid gap-4 border-t border-line pt-5 text-sm sm:mt-8 sm:grid-cols-2 sm:pt-6">
          <div>
            <dt className="text-muted">Nama</dt>
            <dd className="mt-0.5 font-medium text-ink">{user.name}</dd>
          </div>
          <div>
            <dt className="text-muted">Email</dt>
            <dd className="mt-0.5 font-medium text-ink">{user.email}</dd>
          </div>
          <div>
            <dt className="text-muted">Terdaftar sejak</dt>
            <dd className="mt-0.5 font-medium text-ink">{formatDateTime(user.created_at)}</dd>
          </div>
          <div>
            <dt className="text-muted">Jenis akun</dt>
            <dd className="mt-0.5 font-medium text-ink">{user.role === "admin" ? "Administrator" : "Pengguna"}</dd>
          </div>
        </dl>

        <div className="mt-6 border-t border-line pt-5 sm:mt-8 sm:pt-6">
          <Button variant="danger" className="w-full sm:w-auto" onClick={onLogout} loading={loggingOut}>
            Keluar
          </Button>
        </div>
      </Card>

      <Card className="p-5 sm:p-6">
        <h2 className="text-[15px] font-semibold text-ink">Segera hadir</h2>
        <p className="mt-1 text-sm text-muted">
          Jadwal tersimpan serta notifikasi keterlambatan KRL.
        </p>
      </Card>
    </div>
  );
}
