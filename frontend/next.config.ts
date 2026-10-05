import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  allowedDevOrigins: ["my-krl.local", "api.my-krl.local"],
  // Custom dev domains (e.g. my-krl.local and api.my-krl.local via Caddy) must be
  // allowed to use dev resources/HMR. Set in the root .env as ALLOWED_DEV_ORIGINS.
  allowedDevOrigins: (process.env.ALLOWED_DEV_ORIGINS ?? "")
    .split(",")
    .map((host) => host.trim())
    .filter(Boolean),
};

export default nextConfig;
