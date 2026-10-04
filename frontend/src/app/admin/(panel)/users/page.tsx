"use client";

import Link from "next/link";
import { useState } from "react";
import {
  Badge,
  Card,
  EmptyState,
  ErrorState,
  PageHeader,
  Pagination,
  SelectField,
  TableSkeleton,
  TextField,
} from "@/components/ui";
import { DataTable } from "@/components/ui/DataTable";
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

  const { data, error, loading, reload } = useApi(
    `users|${q}|${role}|${page}`,
    () => getAdminUsers({ search: q, role, page }),
  );

  return (
    <>
      <PageHeader
        title="Pengguna"
        description="Cari pengguna dan kelola peran."
      />

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
          <EmptyState
            title="Tidak ada pengguna"
            description="Ubah kata kunci atau filter peran."
          />
        ) : (
          <>
            <DataTable
              busy={loading}
              rows={data.data}
              rowKey={(u) => u.id}
              caption="Daftar pengguna"
              columns={[
                {
                  key: "name",
                  header: "Nama",
                  mobile: "title",
                  className: "font-medium text-ink",
                  cell: (u) => u.name,
                },
                {
                  key: "email",
                  header: "Email",
                  className: "break-all text-slate-600",
                  cell: (u) => u.email,
                },
                {
                  key: "role",
                  header: "Peran",
                  cell: (u) => (
                    <Badge tone={u.role === "admin" ? "info" : "neutral"}>
                      {u.role === "admin" ? "Admin" : "Pengguna"}
                    </Badge>
                  ),
                },
                {
                  key: "created",
                  header: "Terdaftar",
                  className: "whitespace-nowrap text-slate-600",
                  cell: (u) => formatDateTime(u.created_at),
                },
                {
                  key: "actions",
                  header: "Aksi",
                  mobile: "action",
                  className: "text-right",
                  cell: (u) => (
                    <Link
                      href={`/admin/users/${u.id}`}
                      className="text-sm font-semibold text-brand-600 hover:underline"
                    >
                      Detail
                    </Link>
                  ),
                },
              ]}
            />
            <Pagination
              page={data.meta.current_page}
              lastPage={data.meta.last_page}
              total={data.meta.total}
              onChange={setPage}
            />
          </>
        )}
      </Card>
    </>
  );
}
