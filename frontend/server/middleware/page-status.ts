const pages = new Set(["/", "/login", "/register", "/account", "/brand"]);

export default defineEventHandler((event) => {
  const path = getRequestURL(event).pathname.replace(/\/$/, "") || "/";
  if (
    event.method === "GET" &&
    getHeader(event, "accept")?.includes("text/html") &&
    !path.startsWith("/_") &&
    !path.startsWith("/api/") &&
    !path.startsWith("/sanctum/") &&
    !pages.has(path) &&
    !/^\/manage\/events(?:\/(?:new|[1-9][0-9]*))?$/.test(path)
  ) {
    // SPA routing still renders the recovery page; direct visits carry the correct HTTP status.
    setResponseStatus(event, 404);
  }
});
