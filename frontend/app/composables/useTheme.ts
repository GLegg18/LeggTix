type Theme = "system" | "light" | "dark";

export const useTheme = () => {
  const preference = useState<Theme>("theme-preference", () => "system");
  const isDark = useState(
    "theme-is-dark",
    () => import.meta.client && document.documentElement.dataset.theme === "dark",
  );
  const apply = () => {
    const dark =
      preference.value === "dark" ||
      (preference.value === "system" &&
        window.matchMedia("(prefers-color-scheme: dark)").matches);
    isDark.value = dark;
    document.documentElement.dataset.theme = dark ? "dark" : "light";
    document.documentElement.style.colorScheme = dark ? "dark" : "light";
  };
  const setTheme = (value: Theme) => {
    preference.value = value;
    try {
      localStorage.setItem("leggtix-theme", value);
    } catch {
      /* A blocked preference store should not block theme selection. */
    }
    apply();
  };
  let media: MediaQueryList | undefined;
  onBeforeUnmount(() => media?.removeEventListener("change", apply));
  onMounted(() => {
    try {
      const stored = localStorage.getItem("leggtix-theme");
      if (stored === "dark" || stored === "light" || stored === "system")
        preference.value = stored;
    } catch {
      /* Private browser settings may block storage. */
    }
    apply();
    media = window.matchMedia("(prefers-color-scheme: dark)");
    media.addEventListener("change", apply);
  });
  return { preference, isDark, setTheme };
};
