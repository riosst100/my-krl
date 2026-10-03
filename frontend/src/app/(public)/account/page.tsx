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
      <Card className="mx-auto max-w-2xl p-6">
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
    <div className="mx-auto max-w-2xl space-y-6">
      <Card className="p-6 sm:p-8">
        <div className="flex items-center gap-4">
          <div
            className="flex h-14 w-14 items-center justify-center rounded-full bg-brand-600 text-xl font-bold text-white"
            aria-hidden="true"
          >
            {user.name.charAt(0).toUpperCase()}
          </div>
          <div>
            <h1 className="text-2xl font-bold tracking-tight text-ink">{user.name}</h1>
            <p className="text-sm text-muted">{user.email}</p>
          </div>
        </div>

        <dl className="mt-8 grid gap-4 border-t border-line pt-6 text-sm sm:grid-cols-2">
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

        <div className="mt-8 border-t border-line pt-6">
          <Button variant="danger" onClick={onLogout} loading={loggingOut}>
            Keluar
          </Button>
        </div>
      </Card>

      <Card className="p-6">
        <h2 className="font-semibold text-ink">Segera hadir</h2>
        <p className="mt-1 text-sm text-muted">
          Stasiun &amp; rute favorit, jadwal tersimpan, serta notifikasi keterlambatan KRL.
        </p>
      </Card>
    </div>
  );
}
