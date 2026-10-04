"use client";

import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useEffect, useState, type FormEvent } from "react";
import { Alert, Button, Card, TextField } from "@/components/ui";
import { useToast } from "@/components/ui/Toast";
import { ApiError, errorMessage } from "@/lib/api/client";
import { useAuth } from "@/lib/auth/AuthProvider";
import { useHydrated } from "@/lib/hooks/useHydrated";

/** Only allow same-site relative redirects (prevents open redirects). */
function safeNext(value: string | null): string {
  return value && value.startsWith("/") && !value.startsWith("//") && !value.startsWith("/admin") ? value : "/";
}

function useRedirectIfAuthenticated(next: string) {
  const { status } = useAuth();
  const router = useRouter();
  useEffect(() => {
    if (status === "authenticated") router.replace(next);
  }, [status, next, router]);
}

export function LoginForm() {
  const params = useSearchParams();
  const next = safeNext(params.get("next"));
  const router = useRouter();
  const { login } = useAuth();
  const { toast } = useToast();
  const [form, setForm] = useState({ email: "", password: "" });
  const [error, setError] = useState<ApiError | null>(null);
  const [submitting, setSubmitting] = useState(false);
  // Until hydrated a click would submit natively and reload the page (wiping the form).
  const hydrated = useHydrated();

  useRedirectIfAuthenticated(next);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setSubmitting(true);
    setError(null);
    try {
      const user = await login({ ...form, remember: true });
      toast(`Selamat datang, ${user.name}!`, "success");
      router.replace(next);
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError(0, errorMessage(err)));
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <AuthCard title="Masuk" subtitle="Masuk untuk mengakses akun Anda.">
      <form onSubmit={submit} className="space-y-4" noValidate>
        {error && !error.isValidation && <Alert>{error.message}</Alert>}
        {error?.isValidation && error.field("email") && !error.field("password") && <Alert>{error.field("email")}</Alert>}
        <TextField
          label="Email"
          type="email"
          autoComplete="email"
          required
          value={form.email}
          onChange={(e) => setForm({ ...form, email: e.target.value })}
        />
        <TextField
          label="Kata sandi"
          type="password"
          autoComplete="current-password"
          required
          value={form.password}
          error={error?.field("password")}
          onChange={(e) => setForm({ ...form, password: e.target.value })}
        />
        <Button type="submit" size="lg" className="w-full" loading={submitting} disabled={!hydrated}>
          Masuk
        </Button>
      </form>
      <p className="mt-6 text-center text-sm text-muted">
        Belum punya akun?{" "}
        <Link href="/register" className="font-semibold text-brand-600 hover:underline">
          Daftar
        </Link>
      </p>
    </AuthCard>
  );
}

export function RegisterForm() {
  const router = useRouter();
  const { register } = useAuth();
  const { toast } = useToast();
  const [form, setForm] = useState({ name: "", email: "", password: "", password_confirmation: "" });
  const [error, setError] = useState<ApiError | null>(null);
  const [submitting, setSubmitting] = useState(false);
  // Until hydrated a click would submit natively and reload the page (wiping the form).
  const hydrated = useHydrated();

  useRedirectIfAuthenticated("/");

  const set = (key: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement>) => setForm({ ...form, [key]: e.target.value });

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setSubmitting(true);
    setError(null);
    try {
      await register(form);
      toast("Akun berhasil dibuat.", "success");
      router.replace("/");
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError(0, errorMessage(err)));
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <AuthCard title="Daftar" subtitle="Buat akun, lalu pilih rute favorit Anda — kereta berikutnya untuk rute itu tampil di beranda.">
      <form onSubmit={submit} className="space-y-4" noValidate>
        {error && !error.isValidation && <Alert>{error.message}</Alert>}
        <TextField label="Nama" autoComplete="name" required value={form.name} error={error?.field("name")} onChange={set("name")} />
        <TextField
          label="Email"
          type="email"
          autoComplete="email"
          required
          value={form.email}
          error={error?.field("email")}
          onChange={set("email")}
        />
        <TextField
          label="Kata sandi"
          type="password"
          autoComplete="new-password"
          required
          value={form.password}
          error={error?.field("password")}
          hint="Minimal 8 karakter, berisi huruf dan angka."
          onChange={set("password")}
        />
        <TextField
          label="Ulangi kata sandi"
          type="password"
          autoComplete="new-password"
          required
          value={form.password_confirmation}
          onChange={set("password_confirmation")}
        />

        <Button type="submit" size="lg" className="w-full" loading={submitting} disabled={!hydrated}>
          Buat akun
        </Button>
      </form>
      <p className="mt-6 text-center text-sm text-muted">
        Sudah punya akun?{" "}
        <Link href="/login" className="font-semibold text-brand-600 hover:underline">
          Masuk
        </Link>
      </p>
    </AuthCard>
  );
}

export function AuthCard({ title, subtitle, children }: { title: string; subtitle?: string; children: React.ReactNode }) {
  return (
    <div className="mx-auto w-full max-w-md sm:pt-4">
      <Card className="p-5 sm:p-8">
        <h1 className="text-xl font-bold tracking-tight text-ink sm:text-2xl">{title}</h1>
        {subtitle && <p className="mt-1 text-[13px] leading-relaxed text-muted sm:text-sm">{subtitle}</p>}
        <div className="mt-6">{children}</div>
      </Card>
    </div>
  );
}
