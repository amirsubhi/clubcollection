import type { ReactNode } from "react";
import type { LucideIcon } from "lucide-react";
import { cn } from "@/lib/utils";

export type Tone = "accent" | "mint" | "info" | "danger" | "neutral" | "warning";

const toneCls: Record<Tone, string> = {
  accent: "bg-accent text-accent-foreground",
  mint: "bg-mint text-mint-foreground",
  info: "bg-info-soft text-info-foreground",
  danger: "bg-danger-soft text-danger-foreground",
  warning: "bg-warning-soft text-warning-foreground",
  neutral: "bg-secondary text-foreground",
};

/** lucide icon at 1.75 stroke inside a 36px tinted circle (lavender by default; mint only for money in / paid). */
export function IconBubble({ Icon, tone = "accent", size = 36, className }: { Icon: LucideIcon; tone?: Tone; size?: 36 | 44 | 48; className?: string }) {
  const s = size === 36 ? "h-9 w-9 [&_svg]:h-[18px] [&_svg]:w-[18px]" : size === 44 ? "h-11 w-11 [&_svg]:h-5 [&_svg]:w-5" : "h-12 w-12 [&_svg]:h-6 [&_svg]:w-6";
  return (
    <span className={cn("inline-flex shrink-0 items-center justify-center rounded-full", s, toneCls[tone], className)} aria-hidden>
      <Icon strokeWidth={1.75} />
    </span>
  );
}

const avatarTones = ["bg-accent text-accent-foreground", "bg-secondary text-foreground"];

export function initials(name: string) {
  const parts = name.replace(/\b(bin|binti|a\/l|a\/p)\b/gi, "").split(/\s+/).filter(Boolean);
  return ((parts[0]?.[0] ?? "") + (parts[1]?.[0] ?? "")).toUpperCase();
}

export function Avatar({ name, size = 36, className, ring }: { name: string; size?: 28 | 36 | 44; className?: string; ring?: boolean }) {
  const tone = avatarTones[[...name].reduce((s, c) => s + c.charCodeAt(0), 0) % avatarTones.length];
  const s = size === 28 ? "h-7 w-7 text-[11px]" : size === 36 ? "h-9 w-9 text-xs" : "h-11 w-11 text-sm";
  return (
    <span className={cn("inline-flex shrink-0 items-center justify-center rounded-full font-bold", s, tone, ring && "ring-2 ring-card", className)} aria-hidden>
      {initials(name)}
    </span>
  );
}

/** Bank "logo" chip: an inline SVG text mark, never real brand artwork. */
export function BankMark({ mark, className }: { mark: string; className?: string }) {
  return (
    <svg viewBox="0 0 44 28" className={cn("h-7 w-11 shrink-0", className)} aria-hidden>
      <rect x="0.5" y="0.5" width="43" height="27" rx="6" className="fill-secondary stroke-border" />
      <text x="22" y="18" textAnchor="middle" fontSize={mark.length > 3 ? 9.5 : 11} fontWeight="800" className="fill-foreground" style={{ fontFamily: '"Bricolage Grotesque", system-ui, sans-serif', letterSpacing: "0.02em" }}>
        {mark}
      </text>
    </svg>
  );
}

/** FPX text mark (not the real logo). */
export function FpxMark({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 52 28" className={cn("h-7 w-[52px] shrink-0", className)} aria-hidden>
      <rect x="0.5" y="0.5" width="51" height="27" rx="6" className="fill-card stroke-border" />
      <text x="26" y="19" textAnchor="middle" fontSize="13" fontWeight="800" className="fill-primary" style={{ fontFamily: '"Bricolage Grotesque", system-ui, sans-serif', letterSpacing: "0.06em" }}>
        FPX
      </text>
    </svg>
  );
}

/** Rotated rubber stamp, e.g. "LULUS" once approved. */
export function Stamp({ children, tone = "success", className }: { children: ReactNode; tone?: "success" | "danger"; className?: string }) {
  return (
    <div
      className={cn(
        "pointer-events-none select-none rounded-md border-[3px] border-double px-3 py-1 text-center font-display text-2xl font-extrabold uppercase tracking-[0.18em] opacity-90 animate-stamp-in",
        tone === "success" ? "border-success text-success" : "border-danger text-danger",
        className,
      )}
      style={{ transform: "rotate(-14deg)" }}
      aria-hidden
    >
      {children}
    </div>
  );
}
