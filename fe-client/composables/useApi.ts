import { useStore } from "vuex";

export function useApi() {
  const config = useRuntimeConfig();
  const base = (config.public.apiBase as string) || "";
  // useStore() только здесь: при вызове useApi() из setup. Внутри async submit() inject уже недоступен.
  const store = useStore();

  async function api<T>(path: string, opts: Record<string, unknown> = {}): Promise<T> {
    const token = store.state.token as string | null;
    const headers: Record<string, string> = {
      ...(opts.headers as Record<string, string> | undefined),
    };
    if (token) {
      headers.Authorization = `Bearer ${token}`;
    }
    if (
      opts.body &&
      typeof opts.body === "string" &&
      !headers["Content-Type"] &&
      !headers["content-type"]
    ) {
      headers["Content-Type"] = "application/json";
    }
    return await $fetch<T>(`${base}${path}`, {
      ...opts,
      headers,
    });
  }

  return { api, base };
}
