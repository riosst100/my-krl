import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  allowedDevOrigins: ["api.my-krl.local"],
  // Custom dev domains (e.g. jadwal-krl.local via Caddy) must be allowed to use dev resources/HMR.
  allowedDevOrigins: (process.env.ALLOWED_DEV_ORIGINS ?? "")
    .split(",")
    .map((host) => host.trim())
    .filter(Boolean),
};

export default nextConfig;
