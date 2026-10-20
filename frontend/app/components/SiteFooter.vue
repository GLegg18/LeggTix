<template>
  <footer class="site-footer">
    <div
      class="container flex flex-col justify-between gap-7 py-9 sm:flex-row sm:items-center"
    >
      <div>
        <NuxtLink to="/" class="footer-wordmark"
          >LeggTix<span class="brand-pink">.</span></NuxtLink
        >
        <p class="mt-2 text-sm text-muted">Good times. Great company.</p>
      </div>
      <nav
        aria-label="Footer navigation"
        class="flex flex-wrap items-center gap-x-7 gap-y-3 text-sm"
      >
        <NuxtLink to="/#how-it-works" class="text-link">How it works</NuxtLink
        ><NuxtLink to="/brand" class="text-link">The brand</NuxtLink
        ><span class="text-muted"
          >© {{ new Date().getFullYear() }} LeggTix</span
        >
      </nav>
    </div>
  </footer>
</template>
