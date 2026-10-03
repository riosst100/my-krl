"use client";

import { useRouter } from "next/navigation";
import { useEffect, useState, type FormEvent } from "react";
import { Alert, Button, Card, TextField } from "@/components/ui";
import { ApiError, errorMessage } from "@/lib/api/client";
import { useAdminAuth } from "@/lib/auth/AdminAuthProvider";

export default function AdminLoginPage() {
  const router = useRouter();
  const { status, login } = useAdminAuth();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (status === "authenticated") router.replace("/admin/dashboard");
  }, [status, router]);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setSubmitting(true);
    setError(null);
    try {
      await login(email, password);
      router.replace("/admin/dashboard");
    } catch (err) {
      // Same message for wrong password and non-admin accounts (no account enumeration).
      setError(err instanceof ApiError && err.isValidation ? "Email atau kata sandi salah, atau akun tidak memiliki akses admin." : errorMessage(err));
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <main className="flex flex-1 items-center justify-center bg-ink px-4 py-12">
      <div className="w-full max-w-sm">
        <div className="mb-6 flex items-center justify-center gap-2 text-white">
          <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-600 text-sm font-bold">KRL</span>
          <span className="text-lg font-bold">Panel Admin</span>
        </div>
        <Card className="p-6 sm:p-8">
          <h1 className="text-xl font-bold text-ink">Masuk sebagai admin</h1>
          <p className="mt-1 text-sm text-muted">Khusus akun dengan peran administrator.</p>
          <form onSubmit={submit} className="mt-6 space-y-4" noValidate>
            {error && <Alert>{error}</Alert>}
            <TextField label="Email" type="email" autoComplete="username" required value={email} onChange={(e) => setEmail(e.target.value)} />
            <TextField
              label="Kata sandi"
              type="password"
              autoComplete="current-password"
              required
              value={password}
              onChange={(e) => setPassword(e.target.value)}
            />
            <Button type="submit" size="lg" className="w-full" loading={submitting}>
              Masuk
            </Button>
          </form>
        </Card>
      </div>
    </main>
  );
}
