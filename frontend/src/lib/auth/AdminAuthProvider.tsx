"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from "react";
import * as adminApi from "@/lib/api/admin";
import { UNAUTHORIZED_EVENT, type AuthScope } from "@/lib/api/client";
import type { User } from "@/lib/api/types";
import type { AuthStatus } from "./AuthProvider";

interface AdminAuthContextValue {
  admin: User | null;
  status: AuthStatus;
  login: (email: string, password: string) => Promise<User>;
  logout: () => Promise<void>;
}

const AdminAuthContext = createContext<AdminAuthContextValue | null>(null);

/**
 * Admin session state, completely separate from the website AuthProvider.
 * The backend verifies the admin role on every request; this provider only
 * drives the UI (redirects, header).
 */
export function AdminAuthProvider({ children }: { children: ReactNode }) {
  const [admin, setAdmin] = useState<User | null>(null);
  const [status, setStatus] = useState<AuthStatus>("loading");

  useEffect(() => {
    let cancelled = false;
    adminApi
      .getCurrentAdmin()
      .then((me) => {
        if (cancelled) return;
        setAdmin(me);
        setStatus("authenticated");
      })
      .catch(() => {
        if (cancelled) return;
        setAdmin(null);
        setStatus("guest");
      });
    return () => {
      cancelled = true;
    };
  }, []);

  useEffect(() => {
    const onUnauthorized = (event: Event) => {
      if ((event as CustomEvent<{ scope: AuthScope }>).detail?.scope === "admin") {
        setAdmin(null);
        setStatus("guest");
      }
    };
    window.addEventListener(UNAUTHORIZED_EVENT, onUnauthorized);
    return () => window.removeEventListener(UNAUTHORIZED_EVENT, onUnauthorized);
  }, []);

  const login = useCallback(async (email: string, password: string) => {
    const me = await adminApi.adminLogin(email, password);
    setAdmin(me);
    setStatus("authenticated");
    return me;
  }, []);

  const logout = useCallback(async () => {
    try {
      await adminApi.adminLogout();
    } finally {
      setAdmin(null);
      setStatus("guest");
    }
  }, []);

  const value = useMemo(() => ({ admin, status, login, logout }), [admin, status, login, logout]);

  return <AdminAuthContext.Provider value={value}>{children}</AdminAuthContext.Provider>;
}

export function useAdminAuth(): AdminAuthContextValue {
  const context = useContext(AdminAuthContext);
  if (!context) throw new Error("useAdminAuth must be used inside <AdminAuthProvider>");
  return context;
}
