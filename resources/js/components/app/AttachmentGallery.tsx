import { useState } from "react";
import { Download, FileText, Lock } from "lucide-react";
import { AttachmentArt } from "./AttachmentArt";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { attachmentKinds } from "@/lib/fund-meta";
import type { Attachment } from "@/types/domain";
import { cn } from "@/lib/utils";

const kindLabel = (k: Attachment["kind"]) => attachmentKinds.find((x) => x.value === k)?.label ?? k;
const isPdf = (a: Attachment) => a.mime === "application/pdf" || a.name.toLowerCase().endsWith(".pdf");
const isImage = (a: Attachment) => !!a.mime?.startsWith("image/");

/** Real image when there is a URL, a PDF tile for PDFs, otherwise the placeholder drawing. */
function AttachmentPreview({ a, className, title }: { a: Attachment; className?: string; title: string }) {
  if (a.url && isImage(a)) return <img src={a.url} alt={title} loading="lazy" className={cn("object-cover", className)} />;
  if (isPdf(a))
    return (
      <div className={cn("flex flex-col items-center justify-center gap-2 bg-muted text-muted-foreground", className)} role="img" aria-label={title}>
        <FileText className="h-10 w-10" strokeWidth={1.5} aria-hidden />
        <span className="text-xs font-bold">PDF</span>
      </div>
    );
  return <AttachmentArt kind={a.kind} className={className} title={title} />;
}

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
                <AttachmentPreview a={a} className="h-full w-full transition-transform group-hover:scale-[1.02]" title={`${kindLabel(a.kind)} preview`} />
                {isPdf(a) && (
                  <span className="absolute right-1.5 top-1.5 inline-flex items-center gap-0.5 rounded bg-danger px-1.5 py-0.5 text-[10px] font-bold text-danger-on">
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
                <AttachmentPreview a={open} className="aspect-[4/5] h-auto w-full" title={`${kindLabel(open.kind)} full preview`} />
              </div>
              <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
                {open.sha256 && (
                  <>
                    <dt className="text-muted-foreground">SHA-256</dt>
                    <dd className="money truncate font-mono">{open.sha256}</dd>
                  </>
                )}
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
                {open.url && (
                  <Button variant="outline" size="sm" asChild>
                    <a href={open.url} download={open.name}>
                      <Download aria-hidden /> Download
                    </a>
                  </Button>
                )}
              </div>
            </>
          )}
        </DialogContent>
      </Dialog>
    </>
  );
}
