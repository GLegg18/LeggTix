<script setup lang="ts">
const { user, status } = useAuth();
const { isDark, setTheme } = useTheme();
</script>

<template>
  <header class="site-header">
    <div
      class="container flex min-h-22 flex-wrap items-center justify-between gap-4 py-4"
    >
      <NuxtLink to="/" class="brand-link" aria-label="LeggTix home"
        ><img
          src="/brand/leggtix-ticket.svg"
          width="42"
          height="42"
          alt=""
        /><span>Legg<span class="brand-pink">Tix</span></span></NuxtLink
      >
      <nav
        class="flex items-center gap-2 sm:gap-4"
        aria-label="Main navigation"
      >
        <div class="theme-control">
          <button
            type="button"
            class="theme-toggle"
            role="switch"
            aria-label="Dark mode"
            :aria-checked="isDark"
            :title="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
            @click="setTheme(isDark ? 'light' : 'dark')"
          >
            <span class="theme-track" aria-hidden="true">
              <span class="theme-thumb">
                <svg
                  width="18"
                  height="18"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.8"
                  stroke-linecap="round"
                >
                  <path v-if="isDark" d="M12 3a9 9 0 1 0 9 9c-5 2-11-4-9-9Z" />
                  <template v-else>
                    <circle cx="12" cy="12" r="4" />
                    <path
                      d="M12 2v2m0 16v2M2 12h2m16 0h2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"
                    />
                  </template>
                </svg>
              </span>
            </span>
          </button>
        </div>
        <span
          v-if="status === 'loading'"
          class="text-sm text-muted"
          role="status"
          >Checking account…</span
        >
        <NuxtLink
          v-else-if="user"
          to="/account"
          class="button button-small button-primary"
          >My account <span aria-hidden="true">↗</span></NuxtLink
        >
        <template v-else
          ><NuxtLink to="/login" class="nav-link">Sign in</NuxtLink
          ><NuxtLink to="/register" class="button button-small button-primary"
            >Create account <span aria-hidden="true">↗</span></NuxtLink
          ></template
        >
      </nav>
    </div>
  </header>
</template>
