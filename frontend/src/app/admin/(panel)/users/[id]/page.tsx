"use client";

import Link from "next/link";
import { use, useState } from "react";
import { Badge, Button, Card, ErrorState, PageHeader, SelectField, Skeleton } from "@/components/ui";
import { useToast } from "@/components/ui/Toast";
import { getAdminUser, updateUserRole } from "@/lib/api/admin";
import { errorMessage } from "@/lib/api/client";
import type { User, UserRole } from "@/lib/api/types";
import { useAdminAuth } from "@/lib/auth/AdminAuthProvider";
import { formatDateTime } from "@/lib/format";
import { useApi } from "@/lib/hooks/useApi";

export default function AdminUserDetailPage({ params }: PageProps<"/admin/users/[id]">) {
  const { id } = use(params);
  const { admin } = useAdminAuth();
  const { toast } = useToast();
  const { data, error, loading, reload } = useApi(`user|${id}`, () => getAdminUser(id));
  const [updated, setUpdated] = useState<User | null>(null);
  const [role, setRole] = useState<UserRole | null>(null);
  const [saving, setSaving] = useState(false);

  const user = updated ?? data;
  const selectedRole = role ?? user?.role ?? "user";
  const isSelf = !!user && admin?.id === user.id;

  const save = async () => {
    if (!user) return;
    setSaving(true);
    try {
      const result = await updateUserRole(user.id, selectedRole);
      setUpdated(result);
      setRole(null);
      toast(`Peran ${result.name} diubah menjadi ${result.role === "admin" ? "Admin" : "Pengguna"}.`, "success");
    } catch (err) {
      toast(errorMessage(err), "error");
    } finally {
      setSaving(false);
    }
  };

  return (
    <>
      <Link href="/admin/users" className="mb-4 inline-block text-sm text-muted hover:text-ink">
        ← Semua pengguna
      </Link>

      {error ? (
        <Card>
          <ErrorState message={errorMessage(error)} onRetry={reload} />
        </Card>
      ) : loading || !user ? (
        <Card className="p-6">
          <Skeleton className="h-7 w-48" />
          <Skeleton className="mt-4 h-5 w-72" />
        </Card>
      ) : (
        <>
          <PageHeader title={user.name} description={user.email} />
          <div className="grid gap-6 lg:grid-cols-2">
            <Card className="p-6">
              <h2 className="font-semibold text-ink">Informasi akun</h2>
              <dl className="mt-4 space-y-3 text-sm">
                <Row label="ID" value={String(user.id)} />
                <Row label="Nama" value={user.name} />
                <Row label="Email" value={user.email} />
                <Row label="Terdaftar" value={formatDateTime(user.created_at)} />
                <Row label="Diperbarui" value={formatDateTime(user.updated_at)} />
                <div className="flex justify-between gap-4">
                  <dt className="text-muted">Peran</dt>
                  <dd>
                    <Badge tone={user.role === "admin" ? "info" : "neutral"}>{user.role === "admin" ? "Admin" : "Pengguna"}</Badge>
                  </dd>
                </div>
              </dl>
            </Card>

            <Card className="p-6">
              <h2 className="font-semibold text-ink">Ubah peran</h2>
              {isSelf ? (
                <p className="mt-2 text-sm text-muted">Anda tidak dapat mengubah peran akun Anda sendiri.</p>
              ) : (
                <div className="mt-4 space-y-4">
                  <SelectField label="Peran" value={selectedRole} onChange={(e) => setRole(e.target.value as UserRole)}>
                    <option value="user">Pengguna</option>
                    <option value="admin">Admin</option>
                  </SelectField>
                  <p className="text-xs text-muted">Admin dapat mengakses seluruh panel admin, termasuk mengubah peran pengguna lain.</p>
                  <Button onClick={save} loading={saving} disabled={selectedRole === user.role}>
                    Simpan peran
                  </Button>
                </div>
              )}
            </Card>
          </div>
        </>
      )}
    </>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between gap-4">
      <dt className="text-muted">{label}</dt>
      <dd className="text-right font-medium text-ink">{value}</dd>
    </div>
  );
}
