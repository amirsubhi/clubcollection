import { Monitor, Moon, Sun } from "lucide-react";
import { useTheme, type ThemePref } from "@/lib/theme";
import { cn } from "@/lib/utils";

const opts: { value: ThemePref; label: string; bm: string; Icon: typeof Sun }[] = [
  { value: "system", label: "System", bm: "Sistem", Icon: Monitor },
  { value: "light", label: "Light", bm: "Cerah", Icon: Sun },
  { value: "dark", label: "Dark", bm: "Gelap", Icon: Moon },
];

/** Three-way theme switch. `size="lg"` gives 44px targets for member screens; `withLabels` shows the words. */
export function ThemeToggle({ size = "sm", withLabels = false, lang = "en", className }: { size?: "sm" | "lg"; withLabels?: boolean; lang?: "bm" | "en"; className?: string }) {
  const { theme, setTheme } = useTheme();
  return (
    <div role="radiogroup" aria-label={lang === "bm" ? "Tema" : "Theme"} className={cn("inline-flex rounded-full border bg-card p-0.5", className)}>
      {opts.map(({ value, label, bm, Icon }) => {
        const on = theme === value;
        const text = lang === "bm" ? bm : label;
        return (
          <button
            key={value}
            type="button"
            role="radio"
            aria-checked={on}
            aria-label={withLabels ? undefined : text}
            title={text}
            onClick={() => setTheme(value)}
            className={cn(
              "inline-flex items-center justify-center gap-1.5 rounded-full font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
              size === "lg" ? "h-11 min-w-11 px-3 text-sm" : "h-8 min-w-8 px-2 text-xs",
              on ? "bg-primary text-primary-foreground" : "text-muted-foreground hover:text-foreground",
            )}
          >
            <Icon className={size === "lg" ? "h-[18px] w-[18px]" : "h-4 w-4"} strokeWidth={1.75} aria-hidden />
            {withLabels && <span>{text}</span>}
          </button>
        );
      })}
    </div>
  );
}
