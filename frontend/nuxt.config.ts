import tailwindcss from "@tailwindcss/vite";

export default defineNuxtConfig({
  compatibilityDate: "2026-10-16",
  ssr: false,
  spaLoadingTemplate: "spa-loading-template.html",
  devtools: { enabled: false },
  css: ["~/assets/css/main.css"],
  vite: { plugins: [tailwindcss()] },
  runtimeConfig: { apiBase: "http://localhost:8000" },
  app: {
    head: {
      title: "LeggTix — Make room for a good time",
      htmlAttrs: { lang: "en" },
      meta: [
        {
          name: "description",
          content:
            "Your next good time starts here. Create your LeggTix account for events worth showing up for.",
        },
        { name: "theme-color", content: "#f8f7f4" },
      ],
      link: [
        {
          rel: "icon",
          type: "image/svg+xml",
          href: "/brand/leggtix-ticket.svg",
        },
      ],
      // Apply the local preference before CSS/first paint. Only this harmless preference is persisted.
      script: [
        {
          innerHTML: `(function(){var p='system';try{p=localStorage.getItem('leggtix-theme')||'system'}catch(e){}var d=p==='dark'||(p!=='light'&&matchMedia('(prefers-color-scheme: dark)').matches);document.documentElement.dataset.theme=d?'dark':'light';document.documentElement.style.colorScheme=d?'dark':'light'})()`,
          tagPosition: "head",
        },
      ],
    },
  },
  routeRules: {
    "/api/**": { headers: { "cache-control": "no-store" } },
    "/sanctum/**": { headers: { "cache-control": "no-store" } },
    "/account": { headers: { "cache-control": "no-store" } },
  },
  typescript: { strict: true },
});
