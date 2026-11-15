import { Check, Clock, Ban } from "lucide-react";
import { Avatar } from "./Visuals";
import type { FundItem } from "@/data/types";
import { cn } from "@/lib/utils";

/** "Disediakan → Diluluskan" rail: who prepared it, who approved it (maker ≠ checker). */
export function ApprovalRail({ item, preparedAt, approvedAt }: { item: FundItem; preparedAt: string; approvedAt?: string }) {
  const approved = item.status === "approved" || item.status === "void";
  const submitted = item.status !== "draft";
  return (
    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-4" role="group" aria-label="Approval">
      <div className="relative flex min-w-0 items-center gap-3">
        <span className="relative">
          <Avatar name={item.recordedBy} size={44} ring />
          <span className={cn("absolute -bottom-0.5 -right-0.5 flex h-5 w-5 items-center justify-center rounded-full ring-2 ring-card", submitted ? "bg-primary text-primary-foreground" : "bg-secondary text-muted-foreground")}>
            {submitted ? <Check className="h-3 w-3" strokeWidth={3} aria-hidden /> : <Clock className="h-3 w-3" aria-hidden />}
          </span>
        </span>
        <div className="min-w-0">
          <div className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Disediakan · Maker</div>
          <div className="truncate text-sm font-semibold">{item.recordedBy}</div>
          <div className="text-xs text-muted-foreground">{submitted ? `Submitted ${preparedAt}` : "Draft, not submitted"}</div>
        </div>
      </div>
      <div
        className={cn("ml-[21px] h-6 w-0 border-l-2 sm:ml-0 sm:h-0 sm:w-auto sm:min-w-8 sm:flex-1 sm:border-l-0 sm:border-t-2", approved ? "border-primary" : "border-dashed border-input")}
        aria-hidden
      />
      <div className="relative flex min-w-0 items-center gap-3">
        {approved && item.approvedBy ? (
          <span className="relative">
            <Avatar name={item.approvedBy} size={44} ring />
            <span className={cn("absolute -bottom-0.5 -right-0.5 flex h-5 w-5 items-center justify-center rounded-full ring-2 ring-card", item.status === "void" ? "bg-danger text-danger-on" : "bg-success text-success-on")}>
              {item.status === "void" ? <Ban className="h-3 w-3" aria-hidden /> : <Check className="h-3 w-3" strokeWidth={3} aria-hidden />}
            </span>
          </span>
        ) : (
          <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border-2 border-dashed border-input bg-card text-muted-foreground">
            <Clock className="h-5 w-5" strokeWidth={1.75} aria-hidden />
          </span>
        )}
        <div className="min-w-0">
          <div className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Diluluskan · Checker</div>
          <div className="truncate text-sm font-semibold">{approved ? item.approvedBy : submitted ? "Waiting for a second admin" : "—"}</div>
          <div className="text-xs text-muted-foreground">{approved ? `Approved ${approvedAt ?? ""}` : "Must not be the maker or beneficiary"}</div>
        </div>
      </div>
    </div>
  );
}
