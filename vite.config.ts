import { defineConfig } from "vite";
import laravel from "laravel-vite-plugin";
import react from "@vitejs/plugin-react";
import { fileURLToPath, URL } from "node:url";

export default defineConfig({
  plugins: [
    laravel({
      input: ["resources/css/app.css", "resources/css/bootstrap-theme.css", "resources/js/app.tsx", "resources/css/classic.css", "resources/js/classic.ts", "resources/js/classic-charts.ts"],
      refresh: true,
    }),
    react(),
  ],
  resolve: { alias: { "@": fileURLToPath(new URL("./resources/js", import.meta.url)) } },
  build: { target: "es2020" },
});
