export const useManagement = () => {
  const { user, status, restore } = useAuth();
  const allowed = computed(() => status.value === "ready" && !!user.value && ["organiser", "admin"].includes(user.value.role));
  const identity = computed(() => `${status.value}:${user.value?.id}:${user.value?.role}`);
  return { user, allowed, identity, restore };
};
