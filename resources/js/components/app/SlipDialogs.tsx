import { useState } from "react";
import { Check, X } from "lucide-react";
import { Money } from "./Money";
import { Button, type ButtonProps } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from "@/components/ui/dialog";
import { DateHint, Input, Label } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { fmtDate } from "@/lib/format";

/** What the approve / reject dialogs need to know about a pending slip. */
export interface SlipSummary {
  id: number;
  member: { name: string };
  amount: number;
  feeLabel: string;
  transferDate: string | null;
  transferRef: string | null;
  expectedRef: string;
}

interface BaseProps {
  slip: SlipSummary;
  today: string;
  triggerProps?: ButtonProps;
  /** Verifications queue: the confirm button says "… and next" / "… and continue" (the page then moves to another slip). */
  after?: "next" | "continue";
}

/** Approve: the paid date (pre-filled from the member's transfer date) and the bank reference. Same pattern as FundDialogs. */
export function ApproveSlipDialog({ slip, today, triggerProps, after, onConfirm }: BaseProps & { onConfirm: (paidDate: string, reference: string) => void }) {
  const initialDate = slip.transferDate && slip.transferDate <= today ? slip.transferDate : today;
  const [open, setOpen] = useState(false);
  const [paidDate, setPaidDate] = useState(initialDate);
  const [reference, setReference] = useState(slip.transferRef ?? "");
  const reset = (o: boolean) => {
    setOpen(o);
    setPaidDate(initialDate);
    setReference(slip.transferRef ?? "");
  };
  const beforeTransfer = !!slip.transferDate && paidDate < slip.transferDate;
  const valid = /^\d{4}-\d{2}-\d{2}$/.test(paidDate) && paidDate <= today && !beforeTransfer;
  return (
    <Dialog open={open} onOpenChange={reset}>
      <DialogTrigger asChild>
        <Button variant="default" data-slip-action="approve" {...triggerProps}>
          <Check aria-hidden /> <span>Approve <Money sen={slip.amount} /></span>
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            Approve <Money sen={slip.amount} /> from {slip.member.name}?
          </DialogTitle>
          <DialogDescription>
            Check the money is in the club's account first. The fee is then marked paid by bank transfer, the member gets a receipt by email, and this is logged.
          </DialogDescription>
        </DialogHeader>
        <div className="flex items-center justify-between gap-3 rounded-md bg-muted p-3 text-sm">
          <div className="min-w-0">
            <div className="truncate font-medium">{slip.feeLabel}</div>
            <div className="text-xs text-muted-foreground">
              {slip.transferDate ? `Transferred ${fmtDate(slip.transferDate)}` : "No transfer date"} · ref {slip.expectedRef}
            </div>
          </div>
          <Money sen={slip.amount} className="font-display text-xl font-bold" />
        </div>
        <div className="grid gap-4 sm:grid-cols-2">
          <div className="flex flex-col gap-1.5">
            <Label htmlFor={`paid-${slip.id}`}>
              Date received <span className="text-danger">*</span>
            </Label>
            <Input
              id={`paid-${slip.id}`}
              type="date"
              lang="en-MY"
              value={paidDate}
              min={slip.transferDate ?? undefined}
              max={today}
              onChange={(e) => setPaidDate(e.target.value)}
              invalid={!valid}
              required
              aria-describedby={`paid-${slip.id}-note`}
            />
            <DateHint value={paidDate} className="-mt-0.5" />
            <p id={`paid-${slip.id}-note`} className={beforeTransfer ? "text-xs font-medium text-danger-foreground" : "text-xs text-muted-foreground"}>
              {beforeTransfer && slip.transferDate
                ? `Can't be before the transfer date (${fmtDate(slip.transferDate)}).`
                : slip.transferDate && initialDate === slip.transferDate
                  ? "Filled in from the member's transfer date. Change it if the money arrived later."
                  : "When the money reached the club's account."}
            </p>
          </div>
          <div className="flex flex-col gap-1.5">
            <Label htmlFor={`ref-${slip.id}`}>Bank reference</Label>
            <Input id={`ref-${slip.id}`} value={reference} maxLength={100} autoComplete="off" onChange={(e) => setReference(e.target.value)} placeholder={slip.expectedRef} />
            <p className="text-xs text-muted-foreground">From your bank statement. Optional.</p>
          </div>
        </div>
        <DialogFooter className="sm:justify-between">
          <Button variant="outline" onClick={() => reset(false)}>
            Cancel
          </Button>
          <Button
            disabled={!valid}
            onClick={() => {
              setOpen(false);
              onConfirm(paidDate, reference.trim());
            }}
          >
            <Check aria-hidden /> Approve <Money sen={slip.amount} />
            {after && ` and ${after}`}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/** Reject with a reason (at least 5 characters); the member is emailed it and can send a new slip. */
export function RejectSlipDialog({ slip, triggerProps, after, onConfirm }: Omit<BaseProps, "today"> & { onConfirm: (reason: string) => void }) {
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState("");
  return (
    <Dialog open={open} onOpenChange={(o) => (setOpen(o), setReason(""))}>
      <DialogTrigger asChild>
        <Button variant="outline" data-slip-action="reject" {...triggerProps}>
          <X aria-hidden /> Reject
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Reject {slip.member.name}'s slip?</DialogTitle>
          <DialogDescription>The fee goes back to unpaid. The member is emailed your reason and can send a new slip or pay online. The slip is kept on record.</DialogDescription>
        </DialogHeader>
        <div className="flex flex-col gap-1.5">
          <Label htmlFor={`reason-${slip.id}`}>
            Reason <span className="text-danger">*</span>
          </Label>
          <Textarea
            id={`reason-${slip.id}`}
            value={reason}
            maxLength={500}
            onChange={(e) => setReason(e.target.value)}
            placeholder="e.g. No transfer of RM 50.00 on 14 Nov reached the club account. Please check the account number."
          />
          <p className="text-xs text-muted-foreground">The member sees this, so keep it clear and polite.</p>
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={() => setOpen(false)}>
            Cancel
          </Button>
          <Button
            variant="destructive"
            disabled={reason.trim().length < 5}
            onClick={() => {
              setOpen(false);
              onConfirm(reason.trim());
            }}
          >
            <X aria-hidden /> Reject slip{after && ` and ${after}`}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
