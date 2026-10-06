import { redirect } from "next/navigation";

/** The API URLs moved to Configuration → Sync Configuration. */
export default function AdminDataSourcesPage() {
  redirect("/admin/configuration/sync");
}
