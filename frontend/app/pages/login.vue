<script setup lang="ts">
import { api } from "~/utils/api";
import type { User } from "~/composables/useAuth";
useSeoMeta({ title: "Sign in — LeggTix" });
const { user, signedIn } = useAuth();
const { message, errors, retrySeconds, clear, fail } = useFormErrors();
const email = ref("");
const password = ref("");
const code = ref("");
const remember = ref(false);
const challenge = ref("");
const expires = ref("");
const pending = ref(false);
watch(
  user,
  (value) => {
    if (value && !pending.value) navigateTo("/account", { replace: true });
  },
  { immediate: true },
);
const notice = ref("");
const start = async () => {
  clear();
  pending.value = true;
  notice.value = "";
  try {
    const result = await api<{
      user?: User;
      challenge_id?: string;
      expires_at?: string;
    }>("login", {
      method: "POST",
      body: { email: email.value, password: password.value },
    });
    password.value = "";
    if (result.user) {
      signedIn(result.user);
      await navigateTo("/account", { replace: true });
    } else {
      challenge.value = result.challenge_id!;
      expires.value = result.expires_at!;
      code.value = "";
      await nextTick();
      document.getElementById("login-code")?.focus();
    }
  } catch (error) {
    await fail(error);
  } finally {
    pending.value = false;
  }
};
const verify = async () => {
  clear();
  pending.value = true;
  try {
    const result = await api<{ user: User }>(
      `login/email-verifications/${challenge.value}/verify`,
      {
        method: "POST",
        body: { code: code.value, remember_device: remember.value },
      },
    );
    code.value = "";
    challenge.value = "";
    signedIn(result.user);
    await navigateTo("/account", { replace: true });
  } catch (error) {
    await fail(error);
  } finally {
    pending.value = false;
  }
};
const restart = async () => {
  challenge.value = "";
  code.value = "";
  clear();
  notice.value =
    "Enter your password again to request a fresh code. Please allow a minute between requests.";
  await nextTick();
  document.getElementById("login-password")?.focus();
};
</script>

<template>
  <AuthLayout
    :eyebrow="challenge ? 'ONE MORE THING' : 'WELCOME BACK'"
    :title="challenge ? 'Check your inbox.' : 'Good to see you.'"
    :description="
      challenge
        ? `We sent a six-digit sign-in code to ${email.trim().toLowerCase()}.`
        : 'Sign in and make yourself at home.'
    "
  >
    <FormErrors :message="message" :errors="errors" />
    <p v-if="notice" class="notice mb-6" role="status">{{ notice }}</p>
    <form v-if="!challenge" class="form-stack" @submit.prevent="start">
      <div class="field">
        <label for="login-email">Email address</label
        ><input
          id="login-email"
          v-model="email"
          type="email"
          autocomplete="username"
          inputmode="email"
          maxlength="255"
          required
          :disabled="pending"
          :aria-invalid="!!errors.email"
          :aria-describedby="errors.email ? 'login-email-error' : undefined"
        />
        <p v-if="errors.email" id="login-email-error" class="field-error">
          {{ errors.email[0] }}
        </p>
      </div>
      <PasswordField
        id="login-password"
        v-model="password"
        autocomplete="current-password"
        :error="errors.password?.[0]"
        :disabled="pending"
      />
      <p class="field-hint">
        After your password, we’ll email a code to confirm it’s you.
      </p>
      <button
        class="button button-primary w-full"
        :disabled="pending || retrySeconds > 0"
        type="submit"
      >
        {{
          pending
            ? "Signing in…"
            : retrySeconds
              ? `Try again in ${retrySeconds}s`
              : "Sign in"
        }}
        <span v-if="!pending" aria-hidden="true">↗</span>
      </button>
    </form>
    <form v-else class="form-stack" @submit.prevent="verify">
      <div class="field">
        <label for="login-code">Six-digit code</label
        ><input
          id="login-code"
          v-model="code"
          class="code-input"
          type="text"
          inputmode="numeric"
          autocomplete="one-time-code"
          pattern="[0-9]{6}"
          minlength="6"
          maxlength="6"
          required
          :disabled="pending"
          :aria-invalid="!!errors.code"
          :aria-describedby="
            errors.code ? 'login-code-error' : 'login-code-hint'
          "
        />
        <p v-if="errors.code" id="login-code-error" class="field-error">
          {{ errors.code[0] }}
        </p>
        <p v-else id="login-code-hint" class="field-hint">
          Use the latest email. The code expires at
          {{
            new Date(expires).toLocaleTimeString([], {
              hour: "2-digit",
              minute: "2-digit",
            })
          }}.
        </p>
      </div>
      <label class="checkbox-field"
        ><input v-model="remember" type="checkbox" :disabled="pending" /><span
          >Remember this browser for 7 days<small
            >Skip the email code next time. Use only on a private device.</small
          ></span
        ></label
      >
      <button
        type="submit"
        class="button button-primary w-full"
        :disabled="pending || retrySeconds > 0"
      >
        {{
          pending
            ? "Checking code…"
            : retrySeconds
              ? `Try again in ${retrySeconds}s`
              : "Verify and sign in"
        }}
        <span v-if="!pending" aria-hidden="true">↗</span>
      </button>
      <button
        type="button"
        class="text-link justify-self-center"
        :disabled="pending"
        @click="restart"
      >
        Use another email or request a new code
      </button>
      <p class="field-hint text-center">
        Refreshing this page starts sign-in again.
      </p>
    </form>
    <p class="auth-alternative">
      New around here?
      <NuxtLink to="/register" class="text-link font-semibold"
        >Create an account</NuxtLink
      >
    </p>
  </AuthLayout>
</template>
