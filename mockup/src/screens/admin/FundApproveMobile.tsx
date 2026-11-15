import { useState } from "react";
import { CheckCircle2, ChevronLeft, ChevronRight, Paperclip, Undo2 } from "lucide-react";
import { MockupStrip } from "@/components/app/MockupStrip";
import { Money } from "@/components/app/Money";
import { FundStatusBadge, FundTypeBadge } from "@/components/app/StatusBadges";
import { AttachmentGallery } from "@/components/app/AttachmentGallery";
import { Avatar, Stamp } from "@/components/app/Visuals";
import { ApproveDialog, ReturnDialog } from "@/components/app/FundDialogs";
import { Card, CardContent } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { awaitingMyApproval } from "@/data/fund";
import type { FundStatus } from "@/data/types";
import { fmtDate } from "@/lib/format";

/** Phone-first approval queue for the second admin (e.g. opened from a WhatsApp/email notification). */
export function FundApproveMobile() {
  const [idx, setIdx] = useState(0);
  const [done, setDone] = useState<Record<number, FundStatus | "returned">>({});
  const item = awaitingMyApproval[idx];
  const result = done[item.id];
  const left = awaitingMyApproval.filter((i) => !done[i.id]).length;

  return (
    <div className="member-scope flex min-h-full flex-col">
      <MockupStrip />
      <header className="bleed-x sticky top-0 z-20 border-b bg-background/90 py-2.5 backdrop-blur">
        <div className="mx-auto flex max-w-md items-center gap-2">
          <a href="#fund-usage" className="-ml-1.5 inline-flex h-11 w-11 items-center justify-center rounded-full hover:bg-muted" aria-label="Back to Fund Usage">
            <ChevronLeft className="h-5 w-5" />
          </a>
          <div className="min-w-0 flex-1">
            <div className="font-display text-lg font-bold leading-tight">Awaiting my approval</div>
            <div className="text-sm text-muted-foreground">
              {left} left · item {idx + 1} of {awaitingMyApproval.length}
            </div>
          </div>
        </div>
      </header>
      <main className="flex-1 py-4 pb-40">
        <div className="mx-auto flex max-w-md flex-col gap-4">
          <Card className="relative overflow-hidden">
            <CardContent className="flex flex-col gap-3 p-5 sm:p-5">
              <div className="flex flex-wrap items-center gap-1.5">
                <span className="font-mono text-sm">{item.ref}</span>
                <FundTypeBadge type={item.type} />
                <FundStatusBadge status={result === "approved" ? "approved" : item.status} returned={result === "returned"} />
              </div>
              <Money sen={item.amount} className="figure font-display text-[44px] font-extrabold leading-none tracking-tight" />
              {result === "approved" && <Stamp className="absolute right-4 top-14">Lulus</Stamp>}
              <p className="text-base">{item.description}</p>
              <div className="flex items-center gap-2.5 rounded-2xl bg-muted/60 p-2.5 text-sm">
                <Avatar name={item.recordedBy} />
                <span className="min-w-0">
                  <span className="block text-xs font-semibold uppercase tracking-wide text-muted-foreground">Disediakan · Maker</span>
                  <span className="block truncate font-semibold">{item.recordedBy}</span>
                </span>
              </div>
              <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-2 text-base">
                <dt className="text-muted-foreground">Paid to</dt>
                <dd className="text-right">{item.payee}</dd>
                <dt className="text-muted-foreground">Category</dt>
                <dd className="text-right">{item.category}</dd>
                <dt className="text-muted-foreground">Date paid</dt>
                <dd className="text-right">{item.paidDate ? fmtDate(item.paidDate) : "—"}</dd>
                <dt className="text-muted-foreground">From</dt>
                <dd className="text-right">{item.account === "bank" ? "Bank" : "Cash"}</dd>
                <dt className="text-muted-foreground">Minute</dt>
                <dd className="text-right">{item.minuteRef ?? "—"}</dd>
                {item.welfare && (
                  <>
                    <dt className="text-muted-foreground">Beneficiary</dt>
                    <dd className="text-right">{item.welfare.beneficiaryName}</dd>
                    <dt className="text-muted-foreground">Certificate</dt>
                    <dd className="text-right">
                      Sighted by {item.welfare.certificateSightedBy?.split(" ")[0]}, {item.welfare.certificateSightedOn ? fmtDate(item.welfare.certificateSightedOn) : ""}
                    </dd>
                  </>
                )}
              </dl>
            </CardContent>
          </Card>
          <div>
            <h2 className="mb-2 flex items-center gap-1.5 text-base font-semibold">
              <Paperclip className="h-4 w-4" aria-hidden /> Documents ({item.attachments.length}) · tap to enlarge
            </h2>
            <AttachmentGallery items={item.attachments} frozen />
          </div>
          <div className="flex justify-between">
            <Button variant="ghost" className="h-11" disabled={idx === 0} onClick={() => setIdx(idx - 1)}>
              <ChevronLeft aria-hidden /> Previous
            </Button>
            <Button variant="ghost" className="h-11" disabled={idx === awaitingMyApproval.length - 1} onClick={() => setIdx(idx + 1)}>
              Next <ChevronRight aria-hidden />
            </Button>
          </div>
        </div>
      </main>
      <div className="safe-x pb-safe-nav fixed inset-x-0 bottom-0 z-30 border-t bg-card pt-3 shadow-[0_-8px_24px_-12px_rgba(30,27,58,0.25)]">
        <div className="mx-auto max-w-md">
          {result ? (
            <div className="flex min-h-12 items-center gap-2 pb-2 text-base font-semibold">
              {result === "approved" ? (
                <>
                  <CheckCircle2 className="h-5 w-5 text-success" aria-hidden /> Approved. It now counts in the balance.
                </>
              ) : (
                <>
                  <Undo2 className="h-5 w-5 text-warning" aria-hidden /> Returned to {item.recordedBy.split(" ")[0]}.
                </>
              )}
            </div>
          ) : (
            <div className="grid grid-cols-[auto_minmax(0,1fr)] gap-2 pb-2 [&_button]:h-12 [&_button]:rounded-full [&_button]:px-4">
              <ReturnDialog item={item} triggerProps={{ size: "lg", className: "w-full" }} onConfirm={() => setDone({ ...done, [item.id]: "returned" })} />
              <ApproveDialog item={item} triggerProps={{ size: "lg", className: "w-full" }} onConfirm={() => setDone({ ...done, [item.id]: "approved" })} />
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
