import { AlertTriangle, CalendarClock, CheckCircle2, HandHeart, Hourglass, Receipt, RefreshCw, type LucideIcon } from "lucide-react";
import type { FundType, PaymentStatus } from "@/types/domain";
import { cn } from "@/lib/utils";

export type ChipTone = "success" | "warning" | "danger" | "info" | "neutral";

const chipTone: Record<ChipTone, string> = {
  success: "bg-success-soft text-success-foreground border-success/30",
  warning: "bg-warning-soft text-warning-foreground border-warning/30",
  danger: "bg-danger-soft text-danger-foreground border-danger/30",
  info: "bg-info-soft text-info-foreground border-info/30",
  neutral: "bg-secondary text-secondary-foreground border-border",
};

/** Status chip: always icon + text + colour (never colour alone). */
export function StatusChip({ Icon, tone, children, size = "sm", className }: { Icon: LucideIcon; tone: ChipTone; children: React.ReactNode; size?: "sm" | "md"; className?: string }) {
  return (
    <span
      className={cn(
        // Same size as before at normal text; on phones with large text the label wraps instead of widening the page.
        "inline-flex max-w-full items-center gap-1 whitespace-normal rounded-full border py-0.5 font-semibold sm:whitespace-nowrap [&_svg]:shrink-0",
        size === "sm" ? "min-h-6 px-2 text-xs [&_svg]:h-3.5 [&_svg]:w-3.5" : "min-h-7 px-2.5 text-sm [&_svg]:h-4 [&_svg]:w-4",
        chipTone[tone],
        className,
      )}
    >
      <Icon strokeWidth={2} aria-hidden />
      {children}
    </span>
  );
}

export function FundTypeBadge({ type }: { type: FundType }) {
  return type === "welfare" ? (
    <StatusChip Icon={HandHeart} tone="info">
      Welfare
    </StatusChip>
  ) : (
    <StatusChip Icon={Receipt} tone="neutral">
      Expense
    </StatusChip>
  );
}

/** DisplayStatus tones: overdue is amber, not red (a late fee is a reminder, not an error); an FPX payment in progress is amber too, with its own icon. */
export const payMeta: Record<PaymentStatus, { Icon: LucideIcon; tone: ChipTone }> = {
  paid: { Icon: CheckCircle2, tone: "success" },
  due: { Icon: CalendarClock, tone: "neutral" },
  overdue: { Icon: AlertTriangle, tone: "warning" },
  processing: { Icon: RefreshCw, tone: "warning" },
  verifying: { Icon: Hourglass, tone: "warning" },
};

/** English wording on the admin pages (DisplayStatus::adminLabel). */
export const adminPayLabel: Record<PaymentStatus, string> = {
  paid: "Paid",
  due: "Due",
  overdue: "Overdue",
  processing: "FPX in progress",
  verifying: "Awaiting verification",
};

export function PaymentStatusBadge({ status, label, size = "md", className }: { status: PaymentStatus; label: string; size?: "sm" | "md"; className?: string }) {
  const { Icon, tone } = payMeta[status];
  return (
    <StatusChip Icon={Icon} tone={tone} size={size} className={className}>
      {label}
    </StatusChip>
  );
}
