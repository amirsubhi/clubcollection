import type { Config } from "tailwindcss";

const v = (name: string) => `rgb(var(--${name}) / <alpha-value>)`;

export default {
  content: ["./index.html", "./src/**/*.{ts,tsx}"],
  theme: {
    container: { center: true, padding: "1rem" },
    extend: {
      fontFamily: {
        sans: ['"Plus Jakarta Sans"', "ui-sans-serif", "system-ui", "-apple-system", '"Segoe UI"', "Roboto", "Helvetica", "Arial", "sans-serif"],
        display: ['"Bricolage Grotesque"', '"Plus Jakarta Sans"', "ui-sans-serif", "system-ui", "-apple-system", '"Segoe UI"', "sans-serif"],
        mono: ['"JetBrains Mono"', "ui-monospace", "SFMono-Regular", "Menlo", "Consolas", '"Liberation Mono"', "monospace"],
      },
      colors: {
        border: v("border"),
        input: v("input"),
        ring: v("ring"),
        background: v("background"),
        foreground: v("foreground"),
        primary: { DEFAULT: v("primary"), foreground: v("primary-foreground") },
        deep: { DEFAULT: v("deep"), foreground: v("deep-foreground") },
        mint: { DEFAULT: v("mint"), foreground: v("mint-foreground") },
        secondary: { DEFAULT: v("secondary"), foreground: v("secondary-foreground") },
        muted: { DEFAULT: v("muted"), foreground: v("muted-foreground") },
        accent: { DEFAULT: v("accent"), foreground: v("accent-foreground") },
        card: { DEFAULT: v("card"), foreground: v("card-foreground") },
        popover: { DEFAULT: v("popover"), foreground: v("popover-foreground") },
        success: { DEFAULT: v("success"), soft: v("success-soft"), foreground: v("success-foreground"), on: v("on-success") },
        warning: { DEFAULT: v("warning"), soft: v("warning-soft"), foreground: v("warning-foreground") },
        danger: { DEFAULT: v("danger"), soft: v("danger-soft"), foreground: v("danger-foreground"), on: v("on-danger") },
        info: { DEFAULT: v("info"), soft: v("info-soft"), foreground: v("info-foreground") },
        sidebar: v("sidebar"),
        overlay: v("overlay"),
        strip: { DEFAULT: v("strip"), foreground: v("strip-foreground") },
        mock: { DEFAULT: v("mock"), soft: v("mock-soft"), border: v("mock-border") },
        chart: { 1: v("chart-1"), 2: v("chart-2"), 3: v("chart-3"), 4: v("chart-4"), "1-stroke": v("chart-1-stroke"), "2-stroke": v("chart-2-stroke"), "3-stroke": v("chart-3-stroke"), "4-stroke": v("chart-4-stroke"), grid: v("chart-grid"), axis: v("chart-axis") },
      },
      borderRadius: { lg: "var(--radius)", md: "calc(var(--radius) - 2px)", sm: "calc(var(--radius) - 4px)", xl: "calc(var(--radius) + 4px)" },
      keyframes: {
        "draw-tick": { from: { strokeDashoffset: "48" }, to: { strokeDashoffset: "0" } },
        "pop-in": { "0%": { transform: "scale(0.6)", opacity: "0" }, "70%": { transform: "scale(1.06)", opacity: "1" }, "100%": { transform: "scale(1)" } },
        "ring-out": { from: { transform: "scale(0.8)", opacity: "0.55" }, to: { transform: "scale(1.6)", opacity: "0" } },
        "stamp-in": { "0%": { transform: "rotate(-14deg) scale(1.8)", opacity: "0" }, "100%": { transform: "rotate(-14deg) scale(1)", opacity: "1" } },
        "sheet-up": { from: { transform: "translateY(24px)", opacity: "0" }, to: { transform: "translateY(0)", opacity: "1" } },
      },
      animation: {
        "draw-tick": "draw-tick 520ms 280ms cubic-bezier(.65,0,.35,1) both",
        "pop-in": "pop-in 420ms cubic-bezier(.2,.9,.3,1.2) both",
        "ring-out": "ring-out 900ms 200ms ease-out both",
        "stamp-in": "stamp-in 380ms cubic-bezier(.2,.9,.3,1.1) both",
        "sheet-up": "sheet-up 280ms ease-out both",
      },
    },
  },
  plugins: [],
} satisfies Config;
