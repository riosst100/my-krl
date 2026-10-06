import { redirect } from "next/navigation";

/** The section's old single "Sync Data" page: the syncs now have their own pages. */
export default function AdminSyncPage() {
  redirect("/admin/sync/jadwal");
}
