"use client";

import { Card, ErrorState } from "@/components/ui";

// Never show technical details to users; Next.js only passes a digest in production.
export default function PublicError({ retry }: { error: Error & { digest?: string }; retry: () => void }) {
  return (
    <Card>
      <ErrorState
        message="Halaman ini tidak dapat dimuat saat ini. Layanan jadwal mungkin sedang sibuk — coba beberapa saat lagi."
        onRetry={() => retry()}
      />
    </Card>
  );
}
