import { api, ApiError } from "~/utils/api";

export type User = { id: number; name: string; email: string; role: string };

export const useAuth = () => {
  const user = useState<User | null>("auth-user", () => null);
  const status = useState<"loading" | "ready" | "unavailable">(
    "auth-status",
    () => "loading",
  );
  const revision = useState("auth-revision", () => 0);
  const restore = async () => {
    const startedAt = revision.value;
    status.value = "loading";
    try {
      const result = await api<{ data: User }>("user");
      if (startedAt !== revision.value) return;
      user.value = result.data;
      status.value = "ready";
    } catch (error) {
      if (startedAt !== revision.value) return;
      if (error instanceof ApiError && error.status === 401) {
        user.value = null;
        status.value = "ready";
      } else status.value = "unavailable";
    }
  };
  const signedIn = (value: User) => {
    revision.value++;
    user.value = value;
    status.value = "ready";
  };
  const logout = async () => {
    try {
      await api("logout", { method: "POST" });
    } catch (error) {
      if (!(error instanceof ApiError) || error.status !== 401) throw error;
    }
    revision.value++;
    user.value = null;
    status.value = "ready";
  };
  return { user, status, restore, signedIn, logout };
};
