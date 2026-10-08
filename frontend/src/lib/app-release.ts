/**
 * The current Android app release offered on /download. The APKs live in
 * public/downloads/ (built from the my-krl-mobile Flutter project with
 * `flutter build apk --release --split-per-abi`). For a new release: copy the
 * new APKs there, update this file, and remove the old APKs.
 */
export interface ApkFile {
  /** Android ABI, e.g. "arm64-v8a". */
  abi: string;
  /** Path under public/. */
  href: string;
  bytes: number;
  sha256: string;
}

export const APP_RELEASE = {
  version: "1.0.0",
  releasedAt: "2026-10-08",
  minAndroid: "7.0",
  /** SHA-256 of the signing certificate; the same for every release. */
  signerSha256: "AF:30:0C:D2:A7:7E:48:2C:E1:F5:62:19:5D:25:FE:A4:31:D0:3F:EB:47:4A:E8:76:40:0D:48:0C:6B:DC:F3:2C",
  /** Phones from the last ~6 years. */
  main: {
    abi: "arm64-v8a",
    href: "/downloads/my-krl-1.0.0-arm64-v8a.apk",
    bytes: 19_289_095,
    sha256: "7eecf6c2af4eea363b7fa288afa388c076b681abc61026fbe578f9bf95b85434",
  } satisfies ApkFile,
  /** Older 32-bit phones. */
  legacy: {
    abi: "armeabi-v7a",
    href: "/downloads/my-krl-1.0.0-armeabi-v7a.apk",
    bytes: 16_828_787,
    sha256: "779af6a16767f8c03f3b6e5042b79456acb583e04228e0b2c1db0d06e1370401",
  } satisfies ApkFile,
} as const;

/** 19289095 -> "18,4 MB" */
export function formatMegabytes(bytes: number): string {
  return `${new Intl.NumberFormat("id-ID", { maximumFractionDigits: 1 }).format(bytes / 1024 / 1024)} MB`;
}
