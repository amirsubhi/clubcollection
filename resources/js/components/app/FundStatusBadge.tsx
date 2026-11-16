import { Ban, CheckCircle2, Clock, PencilLine, Undo2, type LucideIcon } from "lucide-react";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { StatusChip, type ChipTone } from "@/components/app/StatusBadges";
import { statusMeta } from "@/lib/fund-meta";
import type { FundStatus } from "@/types/domain";

// Admin only: kept out of StatusBadges so member pages never load the Radix tooltip (needs the TooltipProvider in AdminShell).
const fundIcon: Record<FundStatus, { Icon: LucideIcon; tone: ChipTone }> = {
  draft: { Icon: PencilLine, tone: "neutral" },
  submitted: { Icon: Clock, tone: "warning" },
  approved: { Icon: CheckCircle2, tone: "success" },
  void: { Icon: Ban, tone: "danger" },
};

export function FundStatusBadge({ status, returned = false }: { status: FundStatus; returned?: boolean }) {
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
          </StatusChip>
        </span>
      </TooltipTrigger>
      <TooltipContent>BM: {bm}</TooltipContent>
    </Tooltip>
  );
}
