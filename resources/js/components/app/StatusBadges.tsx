import { AlertTriangle, Ban, CheckCircle2, Clock, HandHeart, Hourglass, PencilLine, Receipt, Undo2, type LucideIcon } from "lucide-react";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { statusMeta } from "@/lib/fund-meta";
import type { FundStatus, FundType, PaymentStatus } from "@/types/domain";
import { cn } from "@/lib/utils";

type ChipTone = "success" | "warning" | "danger" | "info" | "neutral";

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
        "inline-flex items-center gap-1 whitespace-nowrap rounded-full border font-semibold",
        size === "sm" ? "h-6 px-2 text-xs [&_svg]:h-3.5 [&_svg]:w-3.5" : "h-7 px-2.5 text-sm [&_svg]:h-4 [&_svg]:w-4",
        chipTone[tone],
        className,
      )}
    >
      <Icon strokeWidth={2} aria-hidden />
      {children}
    </span>
  );
}

const fundIcon: Record<FundStatus, { Icon: LucideIcon; tone: ChipTone }> = {
  draft: { Icon: PencilLine, tone: "neutral" },
  submitted: { Icon: Clock, tone: "warning" },
  approved: { Icon: CheckCircle2, tone: "success" },
  void: { Icon: Ban, tone: "danger" },
};

export function FundStatusBadge({ status, showBm = false, returned = false }: { status: FundStatus; showBm?: boolean; returned?: boolean }) {
  const m = statusMeta[status];
  const { Icon, tone } = returned ? { Icon: Undo2, tone: "warning" as const } : fundIcon[status];
  const label = returned ? "Returned" : m.label;
  const bm = returned ? "Dikembalikan" : m.bm;
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span tabIndex={0} className="inline-flex rounded-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
          <StatusChip Icon={Icon} tone={tone}>
            {label}
            {showBm && <span className="font-medium opacity-80">· {bm}</span>}
          </StatusChip>
        </span>
      </TooltipTrigger>
      <TooltipContent>BM: {bm}</TooltipContent>
    </Tooltip>
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

const payMeta: Record<PaymentStatus, { Icon: LucideIcon; tone: ChipTone }> = {
  paid: { Icon: CheckCircle2, tone: "success" },
  pending: { Icon: Clock, tone: "info" },
  overdue: { Icon: AlertTriangle, tone: "danger" },
  verifying: { Icon: Hourglass, tone: "warning" },
};

export function PaymentStatusBadge({ status, label, size = "md", className }: { status: PaymentStatus; label: string; size?: "sm" | "md"; className?: string }) {
  const { Icon, tone } = payMeta[status];
  return (
    <StatusChip Icon={Icon} tone={tone} size={size} className={className}>
      {label}
    </StatusChip>
  );
}
