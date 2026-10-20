<script setup lang="ts">
import { api, ApiError } from "~/utils/api";
import type { User } from "~/composables/useAuth";
useSeoMeta({ title: "Create your account — LeggTix" });
const { user, signedIn } = useAuth();
const { message, errors, retrySeconds, clear, fail } = useFormErrors();
const step = ref(1);
const email = ref("");
const code = ref("");
const name = ref("");
const password = ref("");
const confirmation = ref("");
const challenge = ref("");
const proof = ref("");
const expiry = ref("");
const pending = ref(false);
watch(
  user,
  (value) => {
    if (value && !pending.value) navigateTo("/account", { replace: true });
  },
  { immediate: true },
);
const resendUntil = ref(0);
const now = ref(Date.now());
const resendSeconds = computed(() =>
  Math.max(
    retrySeconds.value,
    Math.ceil((resendUntil.value - now.value) / 1000),
  ),
);
const expired = computed(
  () => expiry.value && new Date(expiry.value).getTime() <= now.value,
);
let timer: ReturnType<typeof setInterval>;
onMounted(() => {
  timer = setInterval(() => {
    now.value = Date.now();
  }, 1000);
});
onBeforeUnmount(() => {
  clearInterval(timer);
  proof.value = "";
  password.value = "";
  confirmation.value = "";
});
const focus = async (id: string) => {
  await nextTick();
  document.getElementById(id)?.focus();
};
const requestCode = async () => {
  clear();
  pending.value = true;
  try {
    const result = await api<{ challenge_id: string; expires_at: string }>(
      "registration/email-verifications",
      { method: "POST", body: { email: email.value } },
    );
    challenge.value = result.challenge_id;
    expiry.value = result.expires_at;
    proof.value = "";
    code.value = "";
    step.value = 2;
    resendUntil.value = Date.now() + 60000;
    await focus("register-code");
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
    const result = await api<{
      registration_token: string;
      expires_at: string;
    }>(`registration/email-verifications/${challenge.value}/verify`, {
      method: "POST",
      body: { code: code.value },
    });
    proof.value = result.registration_token;
    expiry.value = result.expires_at;
    code.value = "";
    challenge.value = "";
    step.value = 3;
    await focus("register-name");
  } catch (error) {
    await fail(error);
  } finally {
    pending.value = false;
  }
};
const complete = async () => {
  clear();
  if (new TextEncoder().encode(password.value).length > 72) {
    await fail(
      new ApiError(422, "Please choose a shorter password.", {
        password: [
          "Use at most 72 bytes. Emoji and some characters use more than one byte.",
        ],
      }),
    );
    return;
  }
  pending.value = true;
  try {
    const result = await api<{ user: User }>("register", {
      method: "POST",
      body: {
        name: name.value,
        email: email.value,
        password: password.value,
        password_confirmation: confirmation.value,
        registration_token: proof.value,
      },
    });
    proof.value = "";
    password.value = "";
    confirmation.value = "";
    signedIn(result.user);
    await navigateTo("/account?welcome=1", { replace: true });
  } catch (error) {
    await fail(error);
  } finally {
    pending.value = false;
  }
};
const restart = async () => {
  step.value = 1;
  proof.value = "";
  challenge.value = "";
  code.value = "";
  password.value = "";
  confirmation.value = "";
  expiry.value = "";
  clear();
  await focus("register-email");
};
const titles = [
  "Make yourself at home.",
  "Check your inbox.",
  "Make it yours.",
];
const descriptions = computed(() => [
  "Start with your email. We’ll send a code to check it belongs to you.",
  `We sent a six-digit code to ${email.value.trim().toLowerCase()}.`,
  "Email verified. Just your name and a password, and you’re in.",
]);
</script>

<template>
  <AuthLayout
    :eyebrow="`YOUR ACCOUNT / STEP ${step} OF 3`"
    :title="titles[step - 1]!"
    :description="descriptions[step - 1]!"
  >
    <ol class="registration-progress" aria-label="Account creation progress">
      <li
        v-for="(label, index) in ['Email', 'Verify', 'Your details']"
        :key="label"
        :class="{ current: step === index + 1, complete: step > index + 1 }"
        :aria-current="step === index + 1 ? 'step' : undefined"
      >
        <span>{{ step > index + 1 ? "✓" : index + 1 }}</span
        >{{ label }}
      </li>
    </ol>
    <FormErrors :message="message" :errors="errors" />
    <p v-if="expired" class="notice mb-6" role="status">
      {{
        step === 2
          ? "Your code has expired. Request a fresh one below."
          : "Your email verification has expired. Please verify your email again."
      }}
    </p>
    <form v-if="step === 1" class="form-stack" @submit.prevent="requestCode">
      <div class="field">
        <label for="register-email">Email address</label
        ><input
          id="register-email"
          v-model="email"
          type="email"
          autocomplete="email"
          inputmode="email"
          maxlength="255"
          required
          :disabled="pending"
          :aria-invalid="!!errors.email"
          :aria-describedby="errors.email ? 'register-email-error' : undefined"
        />
        <p v-if="errors.email" id="register-email-error" class="field-error">
          {{ errors.email[0] }}
        </p>
      </div>
      <button
        type="submit"
        class="button button-primary w-full"
        :disabled="pending || retrySeconds > 0"
      >
        {{
          pending
            ? "Sending your code…"
            : retrySeconds
              ? `Try again in ${retrySeconds}s`
              : "Send verification code"
        }}
        <span v-if="!pending" aria-hidden="true">↗</span>
      </button>
      <p class="field-hint">
        We’ll use this email for your account and sign-in codes.
      </p>
    </form>
    <form v-else-if="step === 2" class="form-stack" @submit.prevent="verify">
      <div class="field">
        <label for="register-code">Six-digit code</label
        ><input
          id="register-code"
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
            errors.code ? 'register-code-error' : 'register-code-hint'
          "
        />
        <p v-if="errors.code" id="register-code-error" class="field-error">
          {{ errors.code[0] }}
        </p>
        <p v-else id="register-code-hint" class="field-hint">
          Use the latest email. Your code is valid for 10 minutes.
        </p>
      </div>
      <button
        type="submit"
        class="button button-primary w-full"
        :disabled="pending || retrySeconds > 0 || !!expired"
      >
        {{
          pending
            ? "Checking your code…"
            : retrySeconds
              ? `Try again in ${retrySeconds}s`
              : "Verify email"
        }}
        <span v-if="!pending" aria-hidden="true">↗</span>
      </button>
      <div class="flex flex-wrap justify-between gap-3 text-sm">
        <button
          type="button"
          class="text-link"
          :disabled="pending || resendSeconds > 0"
          @click="requestCode"
        >
          {{
            resendSeconds > 0 ? `Resend in ${resendSeconds}s` : "Resend code"
          }}</button
        ><button
          type="button"
          class="text-link"
          :disabled="pending"
          @click="restart"
        >
          Change email
        </button>
      </div>
      <p class="field-hint">
        No email? Check your spam folder. Refreshing this page starts
        registration again.
      </p>
    </form>
    <form v-else class="form-stack" @submit.prevent="complete">
      <div class="verified-email">
        <span aria-hidden="true">✓</span
        ><span>{{ email.trim().toLowerCase() }}</span
        ><button
          type="button"
          class="text-link text-sm"
          :disabled="pending"
          @click="restart"
        >
          Change
        </button>
      </div>
      <div class="field">
        <label for="register-name">Your name</label
        ><input
          id="register-name"
          v-model="name"
          type="text"
          autocomplete="name"
          maxlength="255"
          required
          :disabled="pending"
          :aria-invalid="!!errors.name"
          :aria-describedby="errors.name ? 'register-name-error' : undefined"
        />
        <p v-if="errors.name" id="register-name-error" class="field-error">
          {{ errors.name[0] }}
        </p>
      </div>
      <PasswordField
        id="register-password"
        v-model="password"
        autocomplete="new-password"
        hint="At least 8 characters. A few memorable words work well."
        :error="errors.password?.[0]"
        :disabled="pending"
      /><PasswordField
        id="register-confirmation"
        v-model="confirmation"
        autocomplete="new-password"
        label="Confirm password"
        :error="errors.password_confirmation?.[0]"
        :disabled="pending"
      /><button
        type="submit"
        class="button button-primary w-full"
        :disabled="pending || retrySeconds > 0 || !!expired"
      >
        {{
          pending
            ? "Creating your account…"
            : retrySeconds
              ? `Try again in ${retrySeconds}s`
              : "Create account"
        }}
        <span v-if="!pending" aria-hidden="true">↗</span></button
      ><button
        v-if="expired || errors.registration_token"
        type="button"
        class="text-link justify-self-center"
        :disabled="pending"
        @click="restart"
      >
        Verify your email again
      </button>
    </form>
    <p class="auth-alternative">
      Already one of us?
      <NuxtLink to="/login" class="text-link font-semibold">Sign in</NuxtLink>
    </p>
  </AuthLayout>
</template>
