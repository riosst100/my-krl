import type { Metadata } from "next";
import type { ReactNode } from "react";
import { APP_RELEASE, formatMegabytes } from "@/lib/app-release";
import { formatDateLong } from "@/lib/format";

export const metadata: Metadata = {
  title: "Unduh Aplikasi Android",
  description: "Unduh aplikasi My KRL untuk Android: jadwal KRL, rute favorit, dan posisi kereta dalam genggaman.",
};

const FEATURES: { title: string; body: string; icon: ReactNode }[] = [
  {
    title: "Rute favorit di beranda",
    body: "Simpan sampai 4 rute. Kereta berikutnya dan hitung mundurnya langsung tampil saat aplikasi dibuka.",
    icon: <path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z" />,
  },
  {
    title: "Posisi kereta",
    body: "Lihat stasiun yang dilewati dan perkiraan posisi kereta berdasarkan jadwal.",
    icon: (
      <>
        <path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21Z" />
        <circle cx="12" cy="9.5" r="2.5" />
      </>
    ),
  },
  {
    title: "Tandai kereta Anda",
    body: "Tekan “Saya naik ini” untuk memantau perjalanan dan perkiraan waktu tiba.",
    icon: (
      <>
        <circle cx="12" cy="12" r="9" />
        <path d="M8 12.5l2.5 2.5L16 9.5" />
      </>
    ),
  },
  {
    title: "Data sama dengan situs",
    body: "Jadwal diambil langsung dari My KRL. Akun dan rute favorit Anda tersinkron otomatis.",
    icon: (
      <>
        <path d="M4 12a8 8 0 0 1 13.7-5.7L20 8.5" />
        <path d="M20 4v4.5h-4.5" />
        <path d="M20 12a8 8 0 0 1-13.7 5.7L4 15.5" />
        <path d="M4 20v-4.5h4.5" />
      </>
    ),
  },
];

const STEPS: { title: string; body: string }[] = [
  {
    title: "Unduh APK",
    body: "Tekan tombol Unduh di halaman ini dari HP Android Anda. Jika muncul peringatan, pilih “Tetap unduh”.",
  },
  {
    title: "Izinkan instalasi",
    body: "Buka file yang diunduh. Jika diminta, aktifkan “Izinkan dari sumber ini” untuk browser Anda, lalu kembali.",
  },
  {
    title: "Instal & buka",
    body: "Tekan Instal, lalu buka My KRL. Masuk dengan akun yang sama seperti di situs untuk memakai rute favorit.",
  },
];

export default function DownloadPage() {
  const { main, legacy } = APP_RELEASE;

  return (
    <div className="space-y-10 sm:space-y-14">
      {/* Hero */}
      <section className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-brand-500 via-brand-700 to-[#7f0a1d] px-5 py-8 text-white shadow-raised sm:px-10 sm:py-12">
        <div aria-hidden="true" className="pointer-events-none absolute -right-24 -top-24 h-72 w-72 rounded-full bg-white/10" />
        <div aria-hidden="true" className="pointer-events-none absolute -bottom-32 left-1/3 h-72 w-72 rounded-full bg-white/5" />

        <div className="relative grid items-center gap-10 lg:grid-cols-[1fr_auto]">
          <div className="min-w-0">
            <div className="flex items-center gap-3">
              <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-brand-500 to-brand-700 text-base font-extrabold ring-2 ring-white/60 shadow-lg">
                KRL
              </span>
              <div>
                <p className="text-lg font-extrabold leading-tight">My KRL</p>
                <p className="text-sm text-white/80">Aplikasi Android</p>
              </div>
            </div>

            <h1 className="mt-6 text-3xl font-extrabold leading-tight tracking-tight sm:text-4xl">
              Jadwal KRL di genggaman, langsung dari HP Anda.
            </h1>
            <p className="mt-3 max-w-xl text-sm leading-relaxed text-white/85 sm:text-base">
              Kereta berikutnya dari rute favorit, jadwal setiap stasiun Jabodetabek, dan posisi kereta — dalam satu
              aplikasi yang ringan dan gratis.
            </p>

            <div className="mt-7 flex flex-col gap-3 sm:flex-row sm:items-center">
              <a
                href={main.href}
                download
                className="inline-flex items-center justify-center gap-2.5 rounded-2xl bg-white px-6 py-3.5 text-base font-extrabold text-brand-700 shadow-lg shadow-black/15 transition hover:bg-brand-50 active:scale-[0.99]"
              >
                <DownloadIcon />
                Unduh untuk Android
              </a>
              <p className="text-[13px] leading-snug text-white/80">
                Versi {APP_RELEASE.version} · {formatMegabytes(main.bytes)}
                <br />
                Android {APP_RELEASE.minAndroid} ke atas
              </p>
            </div>

            <p className="mt-4 text-xs text-white/75">
              HP lama (32-bit) tidak bisa memasang file di atas?{" "}
              <a href={legacy.href} download className="font-semibold text-white underline underline-offset-2">
                Unduh versi {legacy.abi} ({formatMegabytes(legacy.bytes)})
              </a>
            </p>
          </div>

          <PhoneMockup />
        </div>
      </section>

      {/* Features */}
      <section aria-labelledby="features-title">
        <h2 id="features-title" className="text-xl font-extrabold tracking-tight text-ink sm:text-2xl">
          Yang bisa Anda lakukan
        </h2>
        <div className="mt-4 grid gap-3 sm:grid-cols-2 sm:gap-4">
          {FEATURES.map((f) => (
            <div key={f.title} className="flex gap-4 rounded-2xl border border-line/80 bg-surface p-4 shadow-card sm:p-5">
              <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600">
                <svg
                  viewBox="0 0 24 24"
                  className="h-[22px] w-[22px]"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth={2}
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  aria-hidden="true"
                >
                  {f.icon}
                </svg>
              </span>
              <div className="min-w-0">
                <h3 className="font-bold text-ink">{f.title}</h3>
                <p className="mt-1 text-sm leading-relaxed text-muted">{f.body}</p>
              </div>
            </div>
          ))}
        </div>
      </section>

      {/* Install steps */}
      <section aria-labelledby="install-title">
        <h2 id="install-title" className="text-xl font-extrabold tracking-tight text-ink sm:text-2xl">
          Cara memasang
        </h2>
        <p className="mt-1 text-sm text-muted">
          Aplikasi dipasang langsung dari situs ini (belum tersedia di Play Store).
        </p>
        <ol className="mt-4 grid gap-3 sm:grid-cols-3 sm:gap-4">
          {STEPS.map((s, i) => (
            <li key={s.title} className="rounded-2xl border border-line/80 bg-surface p-4 shadow-card sm:p-5">
              <span className="flex h-8 w-8 items-center justify-center rounded-full bg-ink text-sm font-extrabold text-white">
                {i + 1}
              </span>
              <h3 className="mt-3 font-bold text-ink">{s.title}</h3>
              <p className="mt-1 text-sm leading-relaxed text-muted">{s.body}</p>
            </li>
          ))}
        </ol>
        <div className="mt-4 flex items-start gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3.5">
          <span aria-hidden="true" className="mt-0.5 text-amber-700">
            <svg viewBox="0 0 24 24" className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth={2.2} strokeLinecap="round" strokeLinejoin="round">
              <path d="M12 3l9.5 17h-19z" />
              <path d="M12 10v4M12 17.5v.01" />
            </svg>
          </span>
          <p className="text-[13px] leading-relaxed text-amber-900">
            Unduh aplikasi My KRL hanya dari <strong>krl.inovasionline.com/download</strong>. Untuk memperbarui, unduh
            versi terbaru dari halaman ini dan pasang di atas versi lama — rute favorit Anda tetap tersimpan di akun.
          </p>
        </div>
      </section>

      {/* Technical details */}
      <section aria-labelledby="info-title" className="rounded-2xl border border-line/80 bg-surface p-4 shadow-card sm:p-6">
        <h2 id="info-title" className="font-extrabold text-ink">
          Informasi rilis
        </h2>
        <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-3 text-sm sm:grid-cols-4">
          <Info label="Versi" value={APP_RELEASE.version} />
          <Info label="Rilis" value={formatDateLong(APP_RELEASE.releasedAt).replace(/^\w+, /, "")} />
          <Info label="Ukuran" value={formatMegabytes(main.bytes)} />
          <Info label="Minimal" value={`Android ${APP_RELEASE.minAndroid}`} />
        </dl>
        <details className="group mt-4 border-t border-line pt-3">
          <summary className="cursor-pointer text-[13px] font-semibold text-brand-600 marker:content-none">
            <span className="group-open:hidden">Tampilkan checksum (untuk verifikasi) ▾</span>
            <span className="hidden group-open:inline">Sembunyikan checksum ▴</span>
          </summary>
          <div className="mt-3 space-y-3 text-xs">
            {[main, legacy].map((f) => (
              <div key={f.abi}>
                <p className="font-semibold text-ink">
                  SHA-256 {f.abi} ({formatMegabytes(f.bytes)})
                </p>
                <p className="mt-0.5 break-all font-mono text-muted">{f.sha256}</p>
              </div>
            ))}
            <div>
              <p className="font-semibold text-ink">Sertifikat penanda tangan (SHA-256)</p>
              <p className="mt-0.5 break-all font-mono text-muted">{APP_RELEASE.signerSha256}</p>
            </div>
          </div>
        </details>
      </section>
    </div>
  );
}

function Info({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-xs text-muted">{label}</dt>
      <dd className="mt-0.5 font-bold text-ink">{value}</dd>
    </div>
  );
}

function DownloadIcon() {
  return (
    <svg viewBox="0 0 24 24" className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth={2.4} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M12 4v11M7 10.5l5 5 5-5" />
      <path d="M5 19.5h14" />
    </svg>
  );
}

/** A sketch of the app's home screen (illustrative data, no real account). */
function PhoneMockup() {
  return (
    <div aria-hidden="true" className="mx-auto hidden w-[260px] shrink-0 sm:block">
      <div className="rounded-[2.2rem] border-[7px] border-ink bg-[#f4f5f9] shadow-2xl shadow-black/30">
        <div className="overflow-hidden rounded-[1.7rem]">
          <div className="bg-gradient-to-br from-brand-500 to-brand-700 px-4 pt-4 pb-14">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-1.5">
                <span className="flex h-6 w-6 items-center justify-center rounded-md bg-brand-600 text-[8px] font-extrabold ring-1 ring-white/60">
                  KRL
                </span>
                <span className="text-xs font-extrabold">My KRL</span>
              </div>
              <span className="rounded-full bg-white/15 px-2 py-0.5 text-[9px] font-bold">07:42 WIB</span>
            </div>
            <p className="mt-4 text-[13px] font-extrabold leading-tight">Mau naik KRL ke mana hari ini?</p>
          </div>
          <div className="-mt-10 space-y-2 px-3 pb-4">
            <div className="rounded-2xl bg-white p-2.5 shadow-lg">
              <div className="rounded-lg border border-line bg-slate-50 px-2 py-1.5">
                <p className="text-[8px] text-muted">Berangkat dari</p>
                <p className="text-[11px] font-extrabold text-ink">Bogor</p>
              </div>
              <div className="mt-1 rounded-lg border border-line bg-slate-50 px-2 py-1.5">
                <p className="text-[8px] text-muted">Tujuan</p>
                <p className="text-[11px] font-extrabold text-ink">Sudirman</p>
              </div>
              <div className="mt-2 rounded-lg bg-brand-600 py-1.5 text-center text-[10px] font-bold">Cari jadwal</div>
            </div>
            <div className="rounded-2xl bg-white p-2.5 text-ink shadow-sm">
              <div className="flex items-center justify-between">
                <span className="rounded bg-sky-100 px-1.5 py-0.5 text-[8px] font-extrabold">● KA 1024</span>
                <span className="rounded-full bg-brand-600 px-1.5 py-0.5 text-[8px] font-bold text-white">3 menit lagi</span>
              </div>
              <div className="mt-2 flex items-end justify-between">
                <div>
                  <p className="text-base font-extrabold leading-none tabular">07:45</p>
                  <p className="text-[8px] text-muted">Bogor</p>
                </div>
                <p className="pb-2 text-[8px] font-bold text-muted">58 mnt</p>
                <div className="text-right">
                  <p className="text-base font-extrabold leading-none tabular">08:43</p>
                  <p className="text-[8px] text-muted">Sudirman</p>
                </div>
              </div>
              <div className="mt-2 grid grid-cols-2 gap-1.5">
                <span className="rounded-md border border-emerald-600/50 bg-emerald-50 py-1 text-center text-[8px] font-bold text-emerald-700">
                  Saya naik ini
                </span>
                <span className="rounded-md bg-ink py-1 text-center text-[8px] font-bold text-white">Detail →</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
