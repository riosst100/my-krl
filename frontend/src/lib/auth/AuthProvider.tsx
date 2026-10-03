"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from "react";
import * as authApi from "@/lib/api/auth";
import { ApiError, UNAUTHORIZED_EVENT, type AuthScope } from "@/lib/api/client";
import type { User } from "@/lib/api/types";

export type AuthStatus = "loading" | "authenticated" | "guest";

interface AuthContextValue {
  user: User | null;
  status: AuthStatus;
  isAuthenticated: boolean;
  login: (input: authApi.LoginInput) => Promise<User>;
  register: (input: authApi.RegisterInput) => Promise<User>;
  logout: () => Promise<void>;
  refresh: () => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | null>(null);

/**
 * Website (non-admin) authentication state. The source of truth is the
 * Laravel session cookie; on load we simply ask /auth/me who we are, so a
 * page refresh or browser restart keeps the user signed in.
 */
export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [status, setStatus] = useState<AuthStatus>("loading");

  const refresh = useCallback(async () => {
    try {
      const me = await authApi.getCurrentUser();
      setUser(me);
      setStatus("authenticated");
    } catch (error) {
      setUser(null);
      // Network errors also land here: treat as signed-out rather than crash.
      setStatus("guest");
      if (!(error instanceof ApiError)) console.error(error);
    }
  }, []);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- initial session check against the API
    refresh();
  }, [refresh]);

  // Any authenticated user API call returning 401 means the session expired.
  useEffect(() => {
    const onUnauthorized = (event: Event) => {
      if ((event as CustomEvent<{ scope: AuthScope }>).detail?.scope === "user") {
        setUser(null);
        setStatus("guest");
      }
    };
    window.addEventListener(UNAUTHORIZED_EVENT, onUnauthorized);
    return () => window.removeEventListener(UNAUTHORIZED_EVENT, onUnauthorized);
  }, []);

  const login = useCallback(async (input: authApi.LoginInput) => {
    const me = await authApi.login(input);
    setUser(me);
    setStatus("authenticated");
    return me;
  }, []);

  const register = useCallback(async (input: authApi.RegisterInput) => {
    const me = await authApi.register(input);
    setUser(me);
    setStatus("authenticated");
    return me;
  }, []);

  const logout = useCallback(async () => {
    try {
      await authApi.logout();
    } finally {
      setUser(null);
      setStatus("guest");
    }
  }, []);

  const value = useMemo(
    () => ({ user, status, isAuthenticated: status === "authenticated", login, register, logout, refresh }),
    [user, status, login, register, logout, refresh],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext);
  if (!context) throw new Error("useAuth must be used inside <AuthProvider>");
  return context;
}
