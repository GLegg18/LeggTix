import { ApiError } from "~/utils/api";

export const useFormErrors = () => {
  const message = ref("");
  const errors = ref<Record<string, string[]>>({});
  const retryUntil = ref(0);
  const now = ref(Date.now());
  const retrySeconds = computed(() =>
    Math.max(0, Math.ceil((retryUntil.value - now.value) / 1000)),
  );
  let timer: ReturnType<typeof setInterval> | undefined;
  onMounted(() => {
    timer = setInterval(() => {
      now.value = Date.now();
    }, 1000);
  });
  onBeforeUnmount(() => clearInterval(timer));
  const clear = () => {
    message.value = "";
    errors.value = {};
  };
  const fail = async (error: unknown) => {
    message.value =
      error instanceof ApiError
        ? error.message
        : "Something went wrong. Please try again.";
    errors.value = error instanceof ApiError ? error.errors : {};
    if (error instanceof ApiError && (error.status === 429 || error.retryAfter))
      retryUntil.value = Date.now() + (error.retryAfter || 60) * 1000;
    await nextTick();
    document.querySelector<HTMLElement>("[data-error-summary]")?.focus();
  };
  return { message, errors, retrySeconds, clear, fail };
};
