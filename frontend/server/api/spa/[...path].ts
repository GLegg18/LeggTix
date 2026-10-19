export default defineEventHandler((event) => {
  const base = useRuntimeConfig(event).apiBase.replace(/\/$/, "");
  const url = getRequestURL(event);
  // The upstream is server configuration, never a user-supplied redirect/URL.
  return proxyRequest(event, `${base}${url.pathname}${url.search}`);
});
