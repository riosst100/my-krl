"use client";

import Link from "next/link";
import { useState } from "react";
import { Badge, Card, EmptyState, ErrorState, PageHeader, Pagination, SelectField, TableSkeleton, TextField } from "@/components/ui";
import { getAdminUsers } from "@/lib/api/admin";
import { errorMessage } from "@/lib/api/client";
import type { UserRole } from "@/lib/api/types";
import { formatDateTime } from "@/lib/format";
import { useApi } from "@/lib/hooks/useApi";
import { useDebounced } from "@/lib/hooks/useDebounced";

export default function AdminUsersPage() {
  const [search, setSearch] = useState("");
  const [role, setRole] = useState<UserRole | "">("");
  const [page, setPage] = useState(1);
  const q = useDebounced(search);

  const { data, error, loading, reload } = useApi(`users|${q}|${role}|${page}`, () => getAdminUsers({ search: q, role, page }));

  return (
    <>
      <PageHeader title="Pengguna" description="Cari pengguna dan kelola peran." />

      <div className="mb-4 grid gap-4 sm:grid-cols-[1fr_200px]">
        <TextField
          label="Cari"
          type="search"
          placeholder="Nama atau email"
          value={search}
          onChange={(e) => {
            setSearch(e.target.value);
            setPage(1);
          }}
        />
        <SelectField
          label="Peran"
          value={role}
          onChange={(e) => {
            setRole(e.target.value as UserRole | "");
            setPage(1);
          }}
        >
          <option value="">Semua</option>
          <option value="user">Pengguna</option>
          <option value="admin">Admin</option>
        </SelectField>
      </div>

      <Card className="overflow-hidden">
        {error ? (
          <ErrorState message={errorMessage(error)} onRetry={reload} />
        ) : loading && !data ? (
          <TableSkeleton />
        ) : !data || data.data.length === 0 ? (
          <EmptyState title="Tidak ada pengguna" description="Ubah kata kunci atau filter peran." />
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm">
                <thead className="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-muted">
                  <tr>
                    <th scope="col" className="px-4 py-3">Nama</th>
                    <th scope="col" className="px-4 py-3">Email</th>
                    <th scope="col" className="px-4 py-3">Peran</th>
                    <th scope="col" className="px-4 py-3">Terdaftar</th>
                    <th scope="col" className="px-4 py-3"><span className="sr-only">Aksi</span></th>
                  </tr>
                </thead>
                <tbody className={`divide-y divide-line ${loading ? "opacity-60" : ""}`}>
                  {data.data.map((u) => (
                    <tr key={u.id}>
                      <td className="px-4 py-3 font-medium text-ink">{u.name}</td>
                      <td className="px-4 py-3 text-slate-600">{u.email}</td>
                      <td className="px-4 py-3">
                        <Badge tone={u.role === "admin" ? "info" : "neutral"}>{u.role === "admin" ? "Admin" : "Pengguna"}</Badge>
                      </td>
                      <td className="whitespace-nowrap px-4 py-3 text-slate-600">{formatDateTime(u.created_at)}</td>
                      <td className="px-4 py-3 text-right">
                        <Link href={`/admin/users/${u.id}`} className="font-semibold text-brand-600 hover:underline">
                          Detail
                        </Link>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <Pagination page={data.meta.current_page} lastPage={data.meta.last_page} total={data.meta.total} onChange={setPage} />
          </>
        )}
      </Card>
    </>
  );
}
