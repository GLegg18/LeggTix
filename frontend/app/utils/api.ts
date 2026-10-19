export class ApiError extends Error {
  constructor(
    public status: number,
    message: string,
    public errors: Record<string, string[]> = {},
    public retryAfter = 0,
  ) {
    super(message);
  }
}

const csrfValue = () => {
  const cookie = document.cookie
    .split("; ")
    .find((value) => value.startsWith("XSRF-TOKEN="));
  return cookie ? decodeURIComponent(cookie.slice("XSRF-TOKEN=".length)) : "";
};

async function csrf() {
  const response = await fetch("/sanctum/csrf-cookie", {
    credentials: "include",
    headers: { Accept: "application/json" },
    cache: "no-store",
  });
  if (!response.ok)
    throw new ApiError(
      response.status,
      "We could not start a secure connection. Please try again.",
    );
}

export async function api<T>(
  path: string,
  options: { method?: "GET" | "POST" | "DELETE"; body?: unknown } = {},
  retry = true,
): Promise<T> {
  const method = options.method ?? "GET";
  try {
    if (method !== "GET" && !csrfValue()) await csrf();
    const response = await fetch(`/api/spa/${path}`, {
      method,
      credentials: "include",
      cache: "no-store",
      redirect: "error",
      headers: {
        Accept: "application/json",
        ...(method !== "GET"
          ? { "Content-Type": "application/json", "X-XSRF-TOKEN": csrfValue() }
          : {}),
      },
      ...(options.body ? { body: JSON.stringify(options.body) } : {}),
    });
    // A 419 is rejected before the action runs, so retrying after a new handshake is safe.
    if (response.status === 419 && retry) {
      await csrf();
      return api<T>(path, options, false);
    }
    if (!response.ok) {
      const data = await response.json().catch(() => ({}));
      const retryAfter = Math.max(
        0,
        Number(response.headers.get("Retry-After")) || 0,
      );
      const message =
        response.status === 429
          ? `A few too many tries. Please wait ${retryAfter || 60} seconds before trying again.`
          : response.status === 401
            ? "Your session has ended. Please sign in again."
            : response.status >= 500
              ? "We cannot connect right now. Please try again in a moment."
              : data.message ||
                "That did not work. Please check your details and try again.";
      throw new ApiError(
        response.status,
        message,
        data.errors ?? {},
        retryAfter,
      );
    }
    return response.status === 204
      ? (undefined as T)
      : ((await response.json()) as T);
  } catch (error) {
    if (error instanceof ApiError) throw error;
    throw new ApiError(
      0,
      "We could not reach LeggTix. Check your connection and try again.",
    );
  }
}
