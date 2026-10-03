import { apiFetch, refreshCsrfCookie } from "./client";
import type { User } from "./types";

export interface LoginInput {
  email: string;
  password: string;
  remember?: boolean;
}

export interface RegisterInput {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
}

export async function login(input: LoginInput): Promise<User> {
  await refreshCsrfCookie();
  const res = await apiFetch<{ data: User }>("/auth/login", { method: "POST", body: { remember: true, ...input } });
  return res.data;
}

export async function register(input: RegisterInput): Promise<User> {
  await refreshCsrfCookie();
  const res = await apiFetch<{ data: User }>("/auth/register", { method: "POST", body: input });
  return res.data;
}

export async function logout(): Promise<void> {
  await apiFetch<void>("/auth/logout", { method: "POST" });
}

export async function getCurrentUser(): Promise<User> {
  const res = await apiFetch<{ data: User }>("/auth/me");
  return res.data;
}
