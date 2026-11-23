import { Monitor, Moon, Sun } from "lucide-react";
import { useTheme, type ThemePref } from "@/lib/theme";
import { SegmentedRadio } from "@/components/ui/segmented-radio";
import { textsIn, type DictKey, type Lang } from "@/lib/i18n";
import { cn } from "@/lib/utils";

const opts: { value: ThemePref; label: DictKey; Icon: typeof Sun }[] = [
  { value: "system", label: "themeSystem", Icon: Monitor },
  { value: "light", label: "themeLight", Icon: Sun },
  { value: "dark", label: "themeDark", Icon: Moon },
];

/** Three-way theme switch. `size="lg"` gives 44px targets for member screens; `withLabels` shows the words. */
export function ThemeToggle({ size = "sm", withLabels = false, lang = "en", className }: { size?: "sm" | "lg"; withLabels?: boolean; lang?: Lang; className?: string }) {
  const { theme, setTheme } = useTheme();
  const t = textsIn(lang);
  return (
    <SegmentedRadio<ThemePref>
      ariaLabel={t("themeLabel")}
      value={theme}
      onChange={setTheme}
      className={cn("inline-flex max-w-full flex-wrap rounded-full border bg-card p-0.5", className)}
      options={opts.map(({ value, label, Icon }) => {
        const text = t(label);
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
