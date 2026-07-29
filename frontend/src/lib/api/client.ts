import axios, {
  AxiosError,
  type AxiosInstance,
  type InternalAxiosRequestConfig,
} from "axios";

import type { ApiError } from "@/types/api";

export const API_BASE_URL =
  process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api/v1";

const TOKEN_KEY = "viking.token";
const GUEST_KEY = "viking.guest";
const LOCALE_KEY = "viking.locale";

/**
 * Browser-only credential storage.
 *
 * Every accessor is guarded for SSR because the same modules are imported by
 * server components during prerender, where `window` does not exist.
 */
export const credentials = {
  getToken(): string | null {
    if (typeof window === "undefined") return null;
    return window.localStorage.getItem(TOKEN_KEY);
  },

  setToken(token: string | null): void {
    if (typeof window === "undefined") return;
    if (token) {
      window.localStorage.setItem(TOKEN_KEY, token);
    } else {
      window.localStorage.removeItem(TOKEN_KEY);
    }
  },

  /**
   * The guest identifier. Minted lazily and kept forever so an anonymous
   * customer can still see their own order history on this device.
   */
  getGuestToken(): string | null {
    if (typeof window === "undefined") return null;
    return window.localStorage.getItem(GUEST_KEY);
  },

  setGuestToken(token: string): void {
    if (typeof window === "undefined") return;
    window.localStorage.setItem(GUEST_KEY, token);
  },

  ensureGuestToken(): string {
    const existing = credentials.getGuestToken();
    if (existing) return existing;

    // 32 lowercase alphanumerics, matching the format the API validates.
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);
    const token = Array.from(bytes, (b) => b.toString(16).padStart(2, "0")).join("");

    credentials.setGuestToken(token);
    return token;
  },

  clear(): void {
    if (typeof window === "undefined") return;
    window.localStorage.removeItem(TOKEN_KEY);
  },
};

export const api: AxiosInstance = axios.create({
  baseURL: API_BASE_URL,
  timeout: 20_000,
  headers: { Accept: "application/json" },
  // Sanctum bearer tokens do not need cookies, and sending them would force a
  // stricter CORS preflight for no gain.
  withCredentials: false,
});

api.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  const token = credentials.getToken();

  if (token) {
    config.headers.set("Authorization", `Bearer ${token}`);
  } else if (typeof window !== "undefined") {
    // Only anonymous requests carry a guest token; once signed in the account
    // is the identity and the device token is irrelevant.
    config.headers.set("X-Guest-Token", credentials.ensureGuestToken());
  }

  if (typeof window !== "undefined") {
    const locale = window.localStorage.getItem(LOCALE_KEY);
    if (locale) config.headers.set("X-Locale", locale);
  }

  return config;
});

/** Fires when the API rejects our token, so the app can clear its session. */
type UnauthorizedHandler = () => void;
let onUnauthorized: UnauthorizedHandler | null = null;

export function setUnauthorizedHandler(handler: UnauthorizedHandler | null): void {
  onUnauthorized = handler;
}

api.interceptors.response.use(
  (response) => response,
  (error: AxiosError<ApiError>) => {
    // A 401 means the stored token is dead — drop it rather than letting every
    // subsequent request fail the same way.
    if (error.response?.status === 401) {
      credentials.clear();
      onUnauthorized?.();
    }

    return Promise.reject(normalizeError(error));
  },
);

/**
 * A request failure with a stable shape, so UI code never has to reach through
 * `error.response.data` and guess.
 */
export class ApiRequestError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly code: string | undefined,
    readonly fieldErrors: Record<string, string[]> | undefined,
    readonly context: Record<string, unknown> | undefined,
  ) {
    super(message);
    this.name = "ApiRequestError";
  }

  /** The first validation message for a field, ready to hand to a form. */
  fieldError(field: string): string | undefined {
    return this.fieldErrors?.[field]?.[0];
  }

  get isValidation(): boolean {
    return this.status === 422;
  }

  get isNetwork(): boolean {
    return this.status === 0;
  }

  get isRateLimited(): boolean {
    return this.status === 429;
  }
}

function normalizeError(error: AxiosError<ApiError>): ApiRequestError {
  // No response at all: DNS failure, CORS block, timeout, offline.
  if (!error.response) {
    return new ApiRequestError(
      error.code === "ECONNABORTED"
        ? "The server took too long to respond."
        : "Could not reach the server.",
      0,
      "network_error",
      undefined,
      undefined,
    );
  }

  const { status, data } = error.response;

  return new ApiRequestError(
    data?.message ?? "Something went wrong.",
    status,
    data?.error,
    data?.errors,
    data?.context,
  );
}

/** Unwraps the `{ data: … }` envelope the API returns. */
export async function getData<T>(url: string, params?: Record<string, unknown>): Promise<T> {
  const response = await api.get<{ data: T }>(url, { params });
  return response.data.data;
}
