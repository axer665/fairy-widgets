const dockerDev =
  process.env.NUXT_DOCKER_DEV === "1" || process.env.CHOKIDAR_USEPOLLING === "true";

/** Публичный URL фронта за reverse-proxy (TLS-терминация на Apache) */
const devOrigin = process.env.NUXT_DEV_ORIGIN || "https://localhost";
const devOriginUrl = (() => {
  try {
    return new URL(devOrigin);
  } catch {
    return new URL("https://localhost");
  }
})();

export default defineNuxtConfig({
  ssr: true,
  /** Разрешённые значения quality для <NuxtImg> / IPX (см. предупреждение в dev). */
  images: {
    qualities: [75, 88],
  },
  devtools: { enabled: false },
  devServer: {
    host: "0.0.0.0",
    port: 3000,
  },
  vite: {
    server: {
      host: "0.0.0.0",
      strictPort: true,
      port: 3000,
      // Абсолютные URL ассетов / HMR за прокси, иначе клиент лезет на :5173 / внутренний хост
      origin: dockerDev ? devOrigin : undefined,
      allowedHosts: dockerDev ? true : undefined,
      hmr: dockerDev
        ? {
            protocol: devOriginUrl.protocol === "https:" ? "wss" : "ws",
            host: devOriginUrl.hostname,
            clientPort:
              devOriginUrl.port !== ""
                ? Number(devOriginUrl.port)
                : devOriginUrl.protocol === "https:"
                  ? 443
                  : 80,
          }
        : undefined,
      watch: dockerDev ? { usePolling: true, interval: 300 } : undefined,
    },
  },
  runtimeConfig: {
    public: {
      apiBase: process.env.NUXT_PUBLIC_API_BASE ?? "",
    },
  },
  nitro: {
    preset: "node-server",
  },
  compatibilityDate: "2025-04-01",
});
