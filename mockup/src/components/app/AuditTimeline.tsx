import { Ban, Check, CirclePlus, Pencil, Send, Undo2, Download, AlertTriangle } from "lucide-react";
import { auditLabels } from "@/data/fund";
import type { AuditEvent } from "@/data/types";
import { fmtDateTime } from "@/lib/format";
import { cn } from "@/lib/utils";

const icons: Record<AuditEvent["action"], { Icon: typeof Check; cls: string; role?: string }> = {
  recorded: { Icon: CirclePlus, cls: "bg-secondary text-foreground", role: "Maker" },
  edited: { Icon: Pencil, cls: "bg-secondary text-foreground", role: "Maker" },
  submitted: { Icon: Send, cls: "bg-warning-soft text-warning-foreground", role: "Maker" },
  returned: { Icon: Undo2, cls: "bg-warning-soft text-warning-foreground", role: "Checker" },
  approved: { Icon: Check, cls: "bg-success-soft text-success-foreground", role: "Checker" },
  void_requested: { Icon: AlertTriangle, cls: "bg-danger-soft text-danger-foreground", role: "Admin 1" },
  voided: { Icon: Ban, cls: "bg-danger-soft text-danger-foreground", role: "Admin 2" },
  downloaded: { Icon: Download, cls: "bg-secondary text-foreground" },
};

/** Newest first; the latest event is highlighted. */
export function AuditTimeline({ events }: { events: AuditEvent[] }) {
  const list = [...events].reverse();
  return (
    <ol className="relative flex flex-col gap-4">
      {list.map((e, idx) => {
        const { Icon, cls, role } = icons[e.action];
        return (
          <li key={idx} className="relative flex gap-3">
            {idx < list.length - 1 && <span className="absolute left-[17px] top-9 h-[calc(100%-20px)] w-px bg-border" aria-hidden />}
            <span className={cn("flex h-9 w-9 shrink-0 items-center justify-center rounded-full", cls, idx === 0 && "ring-2 ring-ring ring-offset-2 ring-offset-card")}>
              <Icon className="h-4 w-4" strokeWidth={1.75} aria-hidden />
            </span>
            <div className="min-w-0 pt-0.5 text-sm">
              <div className="flex flex-wrap items-center gap-x-1.5">
                <span className="font-semibold">{auditLabels[e.action]}</span>
                {idx === 0 && <span className="rounded-full bg-accent px-1.5 text-[11px] font-semibold text-accent-foreground">Latest</span>}
              </div>
              <div className="text-muted-foreground">
                {e.who}
                {role && <span className="text-xs"> · {role}</span>}
              </div>
              <div className="text-xs text-muted-foreground">{fmtDateTime(e.at)}</div>
              {e.note && <div className="mt-1 rounded-md bg-muted px-2 py-1 text-xs text-foreground">{e.note}</div>}
            </div>
          </li>
        );
      })}
    </ol>
  );
}
