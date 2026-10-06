import { redirect } from "next/navigation";

export default function AdminConfigurationPage() {
  redirect("/admin/configuration/sync");
}
