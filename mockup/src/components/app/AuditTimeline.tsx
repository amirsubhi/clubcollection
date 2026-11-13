import { Ban, Check, CirclePlus, Pencil, Send, Undo2, Download, AlertTriangle } from "lucide-react";
import { auditLabels } from "@/data/fund";
import type { AuditEvent } from "@/data/types";
import { fmtDateTime } from "@/lib/format";
import { cn } from "@/lib/utils";

const icons: Record<AuditEvent["action"], { Icon: typeof Check; cls: string }> = {
  recorded: { Icon: CirclePlus, cls: "bg-secondary text-foreground" },
  edited: { Icon: Pencil, cls: "bg-secondary text-foreground" },
  submitted: { Icon: Send, cls: "bg-warning-soft text-warning-foreground" },
  returned: { Icon: Undo2, cls: "bg-warning-soft text-warning-foreground" },
  approved: { Icon: Check, cls: "bg-success-soft text-success-foreground" },
  void_requested: { Icon: AlertTriangle, cls: "bg-danger-soft text-danger-foreground" },
  voided: { Icon: Ban, cls: "bg-danger-soft text-danger-foreground" },
  downloaded: { Icon: Download, cls: "bg-secondary text-foreground" },
};

export function AuditTimeline({ events }: { events: AuditEvent[] }) {
  return (
    <ol className="relative flex flex-col gap-4">
      {events.map((e, idx) => {
        const { Icon, cls } = icons[e.action];
        return (
          <li key={idx} className="relative flex gap-3">
            {idx < events.length - 1 && <span className="absolute left-[15px] top-8 h-[calc(100%-8px)] w-px bg-border" aria-hidden />}
            <span className={cn("flex h-8 w-8 shrink-0 items-center justify-center rounded-full", cls)}>
              <Icon className="h-4 w-4" aria-hidden />
            </span>
            <div className="min-w-0 pt-1 text-sm">
              <div>
                <span className="font-semibold">{auditLabels[e.action]}</span> by {e.who}
              </div>
              <div className="text-xs text-muted-foreground">{fmtDateTime(e.at)}</div>
              {e.note && <div className="mt-0.5 text-xs text-muted-foreground">{e.note}</div>}
            </div>
          </li>
        );
      })}
    </ol>
  );
}
