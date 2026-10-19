<script setup lang="ts">
import { api, ApiError } from "~/utils/api";
useSeoMeta({ title: "Your account — LeggTix", robots: "noindex, nofollow" });
const route = useRoute();
const { user, status, restore, logout } = useAuth();
const { message, errors, clear, fail } = useFormErrors();
const pending = ref(false);
const forgotten = ref(false);
const signOut = async () => {
  clear();
  pending.value = true;
  try {
    await logout();
    await navigateTo("/login", { replace: true });
  } catch (error) {
    await fail(error);
  } finally {
    pending.value = false;
  }
};
const forget = async () => {
  clear();
  pending.value = true;
  try {
    await api("trusted-devices", { method: "DELETE" });
    forgotten.value = true;
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) await restore();
    await fail(error);
  } finally {
    pending.value = false;
  }
};
</script>

<template>
  <main id="main" tabindex="-1" class="container account-page">
    <p class="eyebrow brand-pink">YOUR LITTLE CORNER</p>
    <h1 tabindex="-1" class="mt-4">
      {{ user ? `Hello, ${user.name}.` : "Your account." }}
    </h1>
    <p v-if="status === 'loading'" class="mt-8 text-muted" role="status">
      Getting your account ready…
    </p>
    <section v-else-if="status === 'unavailable'" class="account-card mt-8">
      <h2>We couldn’t reach your account.</h2>
      <p class="mt-3 text-muted">
        Check your connection, then give it another try.
      </p>
      <button class="button button-primary mt-6" @click="restore">
        Try again
      </button>
    </section>
    <template v-else-if="user"
      ><p class="mt-4 text-muted">
        {{
          route.query.welcome === "1"
            ? "You’re in. Your account is ready for good things ahead."
            : "A home for your details. A starting point for what’s next."
        }}
      </p>
      <FormErrors :message="message" :errors="errors" />
      <div class="account-grid">
        <section class="account-card">
          <p class="eyebrow">THE ESSENTIALS</p>
          <h2 class="mt-4">Your details</h2>
          <dl class="account-details">
            <div>
              <dt>Name</dt>
              <dd>{{ user.name }}</dd>
            </div>
            <div>
              <dt>Email address</dt>
              <dd>{{ user.email }}</dd>
            </div>
          </dl>
          <button
            type="button"
            class="button button-outline"
            :disabled="pending"
            @click="signOut"
          >
            {{ pending ? "Please wait…" : "Sign out" }}
            <span aria-hidden="true">↗</span>
          </button>
        </section>
        <div class="flex flex-col gap-6">
          <section class="account-card account-coming">
            <span class="eyebrow">GOOD THINGS TAKE A LITTLE SETTING UP</span>
            <h2 class="mt-4">Next up: being there.</h2>
            <p class="mt-3 text-muted leading-relaxed">
              Event discovery and reservations are on their way. Your account is
              ready for when they arrive.
            </p>
            <NuxtLink to="/" class="text-link inline-block mt-6"
              >Back to the good stuff
              <span aria-hidden="true">↗</span></NuxtLink
            >
          </section>
          <section class="account-card">
            <h2>Keep it yours.</h2>
            <p class="mt-3 text-muted text-sm leading-relaxed">
              Forgot to sign out on a shared browser? Forget remembered browsers
              so the next password sign-in needs an email code. Existing
              signed-in sessions stay active.
            </p>
            <p v-if="forgotten" class="notice mt-4" role="status">
              Remembered browsers forgotten. Future sign-ins will ask for an
              email code.
            </p>
            <button
              type="button"
              class="text-link mt-5 text-sm"
              :disabled="pending"
              @click="forget"
            >
              Forget remembered browsers
            </button>
          </section>
        </div>
      </div></template
    >
    <section v-else class="account-card mt-8">
      <h2>Let’s get you signed in.</h2>
      <p class="mt-3 text-muted">
        Sign in to see your account. Your session may have ended.
      </p>
      <NuxtLink to="/login" class="button button-primary mt-6"
        >Sign in <span aria-hidden="true">↗</span></NuxtLink
      >
    </section>
  </main>
</template>
