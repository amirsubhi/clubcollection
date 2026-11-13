import { useState } from "react";
import { Download, FileText, Lock } from "lucide-react";
import { AttachmentArt } from "./AttachmentArt";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { attachmentKinds } from "@/data/fund";
import type { Attachment } from "@/data/types";

const kindLabel = (k: Attachment["kind"]) => attachmentKinds.find((x) => x.value === k)?.label ?? k;
const fakeHash = (id: string) => `${(id.charCodeAt(1) * 2654435761).toString(16).slice(0, 8)}…e3b0c442`;

export function AttachmentGallery({ items, frozen }: { items: Attachment[]; frozen: boolean }) {
  const [open, setOpen] = useState<Attachment | null>(null);
  if (items.length === 0) return <p className="text-sm text-muted-foreground">No documents attached yet.</p>;
  return (
    <>
      <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3">
        {items.map((a) => (
          <li key={a.id}>
            <button
              type="button"
              onClick={() => setOpen(a)}
              className="group flex w-full flex-col overflow-hidden rounded-lg border bg-card text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              aria-label={`Preview ${a.name}`}
            >
              <div className="relative aspect-[4/5] w-full overflow-hidden bg-muted">
                <AttachmentArt art={a.art} className="h-full w-full transition-transform group-hover:scale-[1.02]" title={`${kindLabel(a.kind)} preview`} />
                {a.mime === "application/pdf" && (
                  <span className="absolute right-1.5 top-1.5 inline-flex items-center gap-0.5 rounded bg-danger px-1.5 py-0.5 text-[10px] font-bold text-white">
                    <FileText className="h-3 w-3" aria-hidden /> PDF
                  </span>
                )}
              </div>
              <div className="flex flex-col gap-1 p-2">
                <Badge variant="secondary" className="self-start">
                  {kindLabel(a.kind)}
                </Badge>
                <span className="truncate text-xs text-muted-foreground">{a.name}</span>
              </div>
            </button>
          </li>
        ))}
      </ul>
      <Dialog open={!!open} onOpenChange={(o) => !o && setOpen(null)}>
        <DialogContent className="max-w-xl">
          {open && (
            <>
              <DialogHeader>
                <DialogTitle className="truncate">{open.name}</DialogTitle>
                <DialogDescription>
                  {kindLabel(open.kind)} · {open.sizeKb} KB
                </DialogDescription>
              </DialogHeader>
              <div className="mx-auto w-full max-w-xs overflow-hidden rounded-md border bg-muted">
                <AttachmentArt art={open.art} className="h-auto w-full" title={`${kindLabel(open.kind)} full preview`} />
              </div>
              <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
                <dt className="text-muted-foreground">SHA-256</dt>
                <dd className="money truncate font-mono">{fakeHash(open.id)}</dd>
                <dt className="text-muted-foreground">Storage</dt>
                <dd>Private, admins only. Every download is logged.</dd>
              </dl>
              <div className="flex flex-wrap items-center justify-between gap-2">
                {frozen ? (
                  <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                    <Lock className="h-3.5 w-3.5" aria-hidden /> Locked since submission
                  </span>
                ) : (
                  <span className="text-xs text-muted-foreground">Editable while draft</span>
                )}
                <Button variant="outline" size="sm" type="button">
                  <Download aria-hidden /> Download
                </Button>
              </div>
            </>
          )}
        </DialogContent>
      </Dialog>
    </>
  );
}
