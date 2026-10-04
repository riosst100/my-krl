import Link from "next/link";

export default function NotFound() {
  return (
    <main className="flex flex-1 flex-col items-center justify-center px-4 py-24 text-center">
      <p className="text-sm font-bold uppercase tracking-wider text-brand-600">404</p>
      <h1 className="mt-2 text-xl font-bold text-ink sm:text-2xl">Halaman tidak ditemukan</h1>
      <p className="mt-2 max-w-md text-sm text-muted">Stasiun atau halaman yang Anda cari tidak tersedia atau sudah dinonaktifkan.</p>
      <Link href="/" className="mt-6 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-brand-600/20 hover:bg-brand-700">
        Kembali ke beranda
      </Link>
    </main>
  );
}
