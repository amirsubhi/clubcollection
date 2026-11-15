import { useState } from "react";
import { Ban, Check, Undo2, Users } from "lucide-react";
import { Money } from "./Money";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button, type ButtonProps } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from "@/components/ui/dialog";
import { Label } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import type { FundItem } from "@/types/domain";

interface BaseProps {
  item: FundItem;
  onConfirm: (reason?: string) => void;
  triggerProps?: ButtonProps;
}

export function ApproveDialog({ item, onConfirm, triggerProps }: BaseProps) {
  const [open, setOpen] = useState(false);
  const [declared, setDeclared] = useState(false);
  return (
    <Dialog open={open} onOpenChange={(o) => (setOpen(o), setDeclared(false))}>
      <DialogTrigger asChild>
        <Button variant="default" {...triggerProps}>
          <Check aria-hidden /> Approve <Money sen={item.amount} />
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            Approve <Money sen={item.amount} /> to {item.welfare ? item.welfare.recipientName : item.payee}?
          </DialogTitle>
          <DialogDescription>
            {item.ref}. Once approved it counts in balances and reports and can't be edited. It can only be voided by two admins.</DialogDescription>
        </DialogHeader>
        <div className="flex items-center justify-between gap-3 rounded-md bg-muted p-3 text-sm">
          <div className="min-w-0">
            <div className="truncate font-medium">{item.payee}</div>
            <div className="text-xs text-muted-foreground">
              {item.category} · {item.attachments.length} document(s)
            </div>
          </div>
          <Money sen={item.amount} className="font-display text-xl font-bold" />
        </div>
        <div className="flex items-start gap-3">
          <Checkbox id="coi" checked={declared} onCheckedChange={(c) => setDeclared(c === true)} />
          <Label htmlFor="coi" className="font-normal leading-snug">
            I declare that I have <strong>no conflict of interest</strong>: I am not the payee, the beneficiary or a relative of either, and I have checked the documents.
            <span className="mt-1 block text-xs text-muted-foreground">Saya mengisytiharkan tiada konflik kepentingan. This declaration is logged.</span>
          </Label>
        </div>
        <DialogFooter className="sm:justify-between">
          <Button variant="outline" onClick={() => setOpen(false)}>
            Cancel
          </Button>
          <Button
            variant="default"
            disabled={!declared}
            onClick={() => {
              setOpen(false);
              onConfirm();
            }}
          >
            <Check aria-hidden /> Approve <Money sen={item.amount} />
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

export function ReturnDialog({ item, onConfirm, triggerProps }: BaseProps) {
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState("");
  return (
    <Dialog open={open} onOpenChange={(o) => (setOpen(o), setReason(""))}>
      <DialogTrigger asChild>
        <Button variant="outline" {...triggerProps}>
          <Undo2 aria-hidden /> Return
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Return {item.ref} to draft</DialogTitle>
          <DialogDescription>{item.recordedBy} will be notified and can fix it and submit again.</DialogDescription>
        </DialogHeader>
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="returnReason">
            Reason <span className="text-danger">*</span>
          </Label>
          <Textarea id="returnReason" value={reason} onChange={(e) => setReason(e.target.value)} placeholder="e.g. Receipt is blurry, please re-upload. Amount on invoice is RM 6,859.00." />
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={() => setOpen(false)}>
            Cancel
          </Button>
          <Button
            disabled={reason.trim().length < 5}
            onClick={() => {
              setOpen(false);
              onConfirm(reason);
            }}
          >
            <Undo2 aria-hidden /> Return to draft
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

export function VoidDialog({ item, onConfirm, triggerProps }: BaseProps) {
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState("");
  return (
    <Dialog open={open} onOpenChange={(o) => (setOpen(o), setReason(""))}>
      <DialogTrigger asChild>
        <Button variant="outline" className="border-danger/40 text-danger-foreground hover:bg-danger-soft" {...triggerProps}>
          <Ban aria-hidden /> Void
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            Void {item.ref} (<Money sen={item.amount} />)?
          </DialogTitle>
          <DialogDescription>Approved records are never deleted. Voiding keeps the record and its documents but removes it from all totals.</DialogDescription>
        </DialogHeader>
        <Alert variant="warning">
          <Users aria-hidden />
          <AlertDescription className="text-foreground">
            <strong>Needs a second admin.</strong> After you request it, another admin must confirm the void. All admins are notified. Until then the record stays
            approved and counted.
          </AlertDescription>
        </Alert>
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="voidReason">
            Reason for voiding <span className="text-danger">*</span>
          </Label>
          <Textarea id="voidReason" value={reason} onChange={(e) => setReason(e.target.value)} placeholder="e.g. Duplicate of FU-2026-0019" />
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
              onConfirm(reason);
            }}
          >
            <Ban aria-hidden /> Request void
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
