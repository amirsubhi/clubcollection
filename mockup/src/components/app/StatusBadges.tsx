import { HandHeart, Receipt } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { statusMeta } from "@/data/fund";
import type { FundStatus, FundType, PaymentStatus } from "@/data/types";

export function FundStatusBadge({ status, showBm = false }: { status: FundStatus; showBm?: boolean }) {
  const m = statusMeta[status];
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span tabIndex={0} className="inline-flex rounded-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
          <Badge variant={m.variant}>
            {m.label}
            {showBm && <span className="font-normal opacity-80">· {m.bm}</span>}
          </Badge>
        </span>
      </TooltipTrigger>
      <TooltipContent>BM: {m.bm}</TooltipContent>
    </Tooltip>
  );
}

export function FundTypeBadge({ type }: { type: FundType }) {
  return type === "welfare" ? (
    <Badge variant="info">
      <HandHeart aria-hidden /> Welfare
    </Badge>
  ) : (
    <Badge variant="outline">
      <Receipt aria-hidden /> Expense
    </Badge>
  );
}

const payVariant: Record<PaymentStatus, "success" | "warning" | "danger"> = {
  paid: "success",
  pending: "warning",
  overdue: "danger",
};

export function PaymentStatusBadge({ status, label }: { status: PaymentStatus; label: string }) {
  return <Badge variant={payVariant[status]}>{label}</Badge>;
}
