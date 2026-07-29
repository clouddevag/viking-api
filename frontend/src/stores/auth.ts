"use client";

import { create } from "zustand";

import { credentials } from "@/lib/api/client";
import type { User } from "@/types/api";

/**
 * Session state.
 *
 * The token itself lives in localStorage via `credentials` (the axios
 * interceptor reads it there); this store holds the decoded user so components
 * can branch on role and permission without a request.
 */
interface AuthState {
  user: User | null;
  status: "idle" | "loading" | "authenticated" | "anonymous";

  setUser: (user: User | null) => void;
  signIn: (token: string, user: User) => void;
  signOut: () => void;
  setStatus: (status: AuthState["status"]) => void;

  can: (permission: string) => boolean;
  hasRole: (...roles: string[]) => boolean;
  isStaff: () => boolean;
}

export const useAuthStore = create<AuthState>()((set, get) => ({
  user: null,
  status: "idle",

  setUser: (user) =>
    set({ user, status: user ? "authenticated" : "anonymous" }),

  signIn: (token, user) => {
    credentials.setToken(token);
    set({ user, status: "authenticated" });
  },

  signOut: () => {
    credentials.clear();
    set({ user: null, status: "anonymous" });
  },

  setStatus: (status) => set({ status }),

  /**
   * The owner role bypasses every check, mirroring the `Gate::before` hook on
   * the server so the UI never offers an action the API would refuse — or
   * hides one it would allow.
   */
  can: (permission) => {
    const user = get().user;
    if (!user) return false;
    if (user.roles?.includes("super-admin")) return true;

    return user.permissions?.includes(permission) ?? false;
  },

  hasRole: (...roles) => {
    const user = get().user;
    if (!user?.roles) return false;

    return roles.some((role) => user.roles!.includes(role));
  },

  isStaff: () => {
    const user = get().user;
    if (!user?.roles) return false;

    return user.roles.some((role) => role !== "customer");
  },
}));
