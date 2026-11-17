import { Monitor, Moon, Sun } from "lucide-react";
import { useTheme, type ThemePref } from "@/lib/theme";
import { SegmentedRadio } from "@/components/ui/segmented-radio";
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
    <SegmentedRadio<ThemePref>
      ariaLabel={lang === "bm" ? "Tema" : "Theme"}
      value={theme}
      onChange={setTheme}
      className={cn("inline-flex max-w-full flex-wrap rounded-full border bg-card p-0.5", className)}
      options={opts.map(({ value, label, bm, Icon }) => {
        const text = lang === "bm" ? bm : label;
        return {
          value,
          title: text,
          ariaLabel: withLabels ? undefined : text,
          label: (
            <>
              <Icon className={size === "lg" ? "h-[18px] w-[18px]" : "h-4 w-4"} strokeWidth={1.75} aria-hidden />
              {withLabels && <span>{text}</span>}
            </>
          ),
        };
      })}
      itemClassName={(on) =>
        cn(
          "inline-flex items-center justify-center gap-1.5 rounded-full font-medium transition-colors",
          // Icon-only targets are sized in px, so 200% text does not push the three buttons off a narrow screen.
          size === "lg" ? (withLabels ? "h-11 min-w-11 px-3 text-sm" : "h-[44px] w-[44px] px-0 text-sm") : "h-8 min-w-8 px-2 text-xs",
          on ? "bg-primary text-primary-foreground" : "text-muted-foreground hover:text-foreground",
        )
      }
    />
  );
}
