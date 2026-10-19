export default defineEventHandler((event) => {
  const base = useRuntimeConfig(event).apiBase.replace(/\/$/, "");
  return proxyRequest(event, `${base}/sanctum/csrf-cookie`);
});
