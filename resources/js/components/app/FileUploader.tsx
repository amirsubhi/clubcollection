import { useId, useRef, useState } from "react";
import { Camera, FileText, ImageIcon, Trash2, UploadCloud } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { attachmentKinds } from "@/lib/fund-meta";
import type { AttachmentKind } from "@/types/domain";
import { cn } from "@/lib/utils";

export interface UploadItem {
  id: string;
  name: string;
  sizeKb: number;
  isPdf: boolean;
  kind: AttachmentKind | "";
  /** The picked file, for forms that upload it (e.g. Inertia useForm). */
  file?: File;
}

/** Visible strings, so member pages can pass BM/EN copy (admin pages use the English defaults). */
export interface UploaderText {
  dropPrefix: string;
  browse: string;
  limits: string;
  takePhoto: string;
  cameraHint: string;
  remove: (name: string) => string;
  /** Shown when picked files are over maxKb (they are not added). */
  tooLarge: (names: string, mb: number) => string;
}

const defaultText = (maxFiles: number): UploaderText => ({
  dropPrefix: "Drag files here or",
  browse: "browse",
  limits: `PDF, JPG, PNG or WebP · up to 10 MB each · max ${maxFiles} files`,
  takePhoto: "Take photo",
  cameraHint: "On a phone this opens the camera.",
  remove: (name) => `Remove ${name}`,
  tooLarge: (names, mb) => `${names}: larger than ${mb} MB, so not added. Choose a smaller file.`,
});

export interface FileUploaderProps {
  files: UploadItem[];
  onAdd: (files: UploadItem[]) => void;
  onRemove: (id: string) => void;
  onKindChange?: (id: string, kind: AttachmentKind) => void;
  error?: string;
  maxFiles?: number;
  /** Ask for the kind of each document (Fund Usage). Off for single-purpose uploads such as a transfer slip. */
  showKind?: boolean;
  text?: Partial<UploaderText>;
  /** Larger type and touch targets for member pages. */
  comfortable?: boolean;
  /** Per-file size limit (KB). Bigger files are refused here, before any upload, with text.tooLarge. */
  maxKb?: number;
}

function toItems(list: FileList | null): UploadItem[] {
  if (!list) return [];
  return Array.from(list).map((f, i) => ({
    id: `${Date.now()}-${i}-${f.name}`,
    name: f.name,
    sizeKb: Math.max(1, Math.round(f.size / 1024)),
    isPdf: f.type === "application/pdf" || f.name.toLowerCase().endsWith(".pdf"),
    kind: "",
    file: f,
  }));
}

/** Multi-file uploader: drag-drop or browse, camera capture on phones, per-file kind and remove. Upload is left to the parent form (e.g. Inertia useForm). */
export function FileUploader({ files, onAdd: onAddAll, onRemove, onKindChange, error, maxFiles = 10, showKind = true, text, comfortable = false, maxKb }: FileUploaderProps) {
  const inputId = useId();
  const tx = { ...defaultText(maxFiles), ...text };
  const camRef = useRef<HTMLInputElement>(null);
  const [over, setOver] = useState(false);
  const [sizeError, setSizeError] = useState<string | null>(null);
  // Refuse oversize files on the device: a slow upload that the server then
  // rejects (or that PHP drops whole) is the worst way to find out.
  const onAdd = (picked: UploadItem[]) => {
    const tooBig = maxKb ? picked.filter((f) => (f.file ? f.file.size > maxKb * 1024 : f.sizeKb > maxKb)) : [];
    setSizeError(tooBig.length ? tx.tooLarge(tooBig.map((f) => f.name).join(", "), Math.round((maxKb ?? 0) / 1024)) : null);
    const ok = picked.filter((f) => !tooBig.includes(f));
    if (ok.length) onAddAll(ok);
  };
  const shownError = error ?? sizeError ?? undefined;
  return (
    <div className="flex flex-col gap-3">
      <label
        htmlFor={inputId}
        onDragOver={(e) => {
          e.preventDefault();
          setOver(true);
        }}
        onDragLeave={() => setOver(false)}
        onDrop={(e) => {
          e.preventDefault();
          setOver(false);
          onAdd(toItems(e.dataTransfer.files));
        }}
        className={cn(
          "flex cursor-pointer flex-col items-center gap-2 rounded-lg border-2 border-dashed bg-muted/50 px-4 py-7 text-center transition-colors focus-within:ring-2 focus-within:ring-ring",
          over ? "border-primary bg-accent" : "border-input hover:bg-muted",
          shownError && "border-danger bg-danger-soft/50",
        )}
      >
        <UploadCloud className="h-8 w-8 text-muted-foreground" aria-hidden />
        <span className={comfortable ? "text-base font-medium" : "text-sm font-medium"}>
          {tx.dropPrefix} <span className="text-primary underline">{tx.browse}</span>
        </span>
        <span className={comfortable ? "text-sm text-muted-foreground" : "text-xs text-muted-foreground"}>{tx.limits}</span>
        <input
          id={inputId}
          type="file"
          multiple
          accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp"
          className="sr-only"
          onChange={(e) => {
            onAdd(toItems(e.target.files));
            e.target.value = "";
          }}
        />
      </label>
      <div className="flex flex-wrap items-center gap-2">
        <Button type="button" variant="outline" size={comfortable ? "lg" : "sm"} className={comfortable ? "h-11 rounded-full" : undefined} onClick={() => camRef.current?.click()}>
          <Camera aria-hidden /> {tx.takePhoto}
        </Button>
        <span className={comfortable ? "text-sm text-muted-foreground" : "text-xs text-muted-foreground"}>{tx.cameraHint}</span>
        <input
          ref={camRef}
          type="file"
          accept="image/*"
          capture="environment"
          className="sr-only"
          tabIndex={-1}
          aria-hidden
          onChange={(e) => {
            onAdd(toItems(e.target.files));
            e.target.value = "";
          }}
        />
      </div>
      {shownError && (
        <p role="alert" className={cn("font-medium text-danger-foreground", comfortable ? "text-sm" : "text-xs")}>
          {shownError}
        </p>
      )}
      {files.length > 0 && (
        <ul className="flex flex-col divide-y rounded-lg border">
          {files.map((f) => (
            <li key={f.id} className="flex flex-col gap-2 p-2.5 sm:flex-row sm:items-center">
              <div className="flex min-w-0 flex-1 items-center gap-2.5">
                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-muted">
                  {f.isPdf ? <FileText className="h-5 w-5 text-danger" aria-hidden /> : <ImageIcon className="h-5 w-5 text-info" aria-hidden />}
                </div>
                <div className="min-w-0">
                  <div className={cn("truncate font-medium", comfortable ? "text-base" : "text-sm")}>{f.name}</div>
                  <div className={comfortable ? "text-sm text-muted-foreground" : "text-xs text-muted-foreground"}>{f.sizeKb} KB</div>
                </div>
                {!showKind && (
                  <Button type="button" variant="ghost" size="icon" className="h-11 w-11 shrink-0" onClick={() => onRemove(f.id)} aria-label={tx.remove(f.name)}>
                    <Trash2 className="text-danger" />
                  </Button>
                )}
              </div>
              {showKind && (
                <div className="flex items-center gap-2">
                  <Select value={f.kind || undefined} onValueChange={(v) => onKindChange?.(f.id, v as AttachmentKind)}>
                    <SelectTrigger className="h-9 w-full sm:w-44" aria-label={`Kind of ${f.name}`} invalid={!f.kind && !!error}>
                      <SelectValue placeholder="Kind of document" />
                    </SelectTrigger>
                    <SelectContent>
                      {attachmentKinds.map((k) => (
                        <SelectItem key={k.value} value={k.value}>
                          {k.label} · {k.bm}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <Button type="button" variant="ghost" size="icon" className="h-9 w-9 shrink-0" onClick={() => onRemove(f.id)} aria-label={tx.remove(f.name)}>
                    <Trash2 className="text-danger" />
                  </Button>
                </div>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
