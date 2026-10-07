import { redirect } from "next/navigation";

/** Syncing runs on krl-sync (Vercel); this section shows its history. */
export default function AdminSyncPage() {
  redirect("/admin/sync/riwayat");
}
