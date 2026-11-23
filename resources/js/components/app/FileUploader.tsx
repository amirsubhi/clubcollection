import { useId, useRef, useState } from "react";
import { Camera, FileText, ImageIcon, Trash2, UploadCloud } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { attachmentKinds } from "@/lib/fund-meta";
import { fill, textsIn, usePageLang, type Lang } from "@/lib/i18n";
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
  /** Touch screens, comfortable mode: the button that opens the file picker (no drag and drop there). */
  chooseFile: string;
  remove: (name: string) => string;
  /** Shown when picked files are over maxKb (they are not added). */
  tooLarge: (names: string, mb: number) => string;
}

/** Defaults in the page's language (admin pages are English; member pages normally pass their own copy). */
const defaultText = (maxFiles: number, lang: Lang): UploaderText => {
  const t = textsIn(lang);
  return {
    dropPrefix: t("uploaderDropPrefix"),
    browse: t("uploaderBrowse"),
    limits: fill(t("uploaderLimits"), { n: maxFiles }),
    takePhoto: t("uploaderTakePhoto"),
    cameraHint: t("uploaderCameraHint"),
    chooseFile: t("uploaderChooseFile"),
    remove: (name) => fill(t("uploaderRemove"), { name }),
    tooLarge: (names, mb) => fill(t("uploaderTooLarge"), { names, mb }),
  };
};

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
  /** Larger type and touch targets for member pages. On touch screens the camera is the main control and there is no drop zone. */
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
  const errorId = `${inputId}-error`;
  const hintId = `${inputId}-hint`;
  const tx = { ...defaultText(maxFiles, usePageLang()), ...text };
  const camRef = useRef<HTMLInputElement>(null);
  const fileRef = useRef<HTMLInputElement>(null);
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
  // Every way in (hidden input, drop zone, touch buttons) is described by the limits and, when present, the error.
  const describedBy = shownError ? `${errorId} ${hintId}` : hintId;
  return (
    <div className="flex flex-col gap-3">
      <input
        ref={fileRef}
        id={inputId}
        type="file"
        multiple
        accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp"
        // The buttons (browse, camera) are the tab stops, not this hidden input.
        tabIndex={-1}
        className="sr-only"
        aria-label={tx.chooseFile}
        aria-invalid={shownError ? true : undefined}
        aria-describedby={describedBy}
        // A form that focuses the first invalid field lands here; pass focus to the first visible
        // button (browse, or the camera on touch) so keyboard users see where they are.
        onFocus={(e) => {
          const btn = Array.from(e.currentTarget.parentElement?.querySelectorAll<HTMLButtonElement>("button") ?? []).find(
            (b) => b.offsetParent !== null && !b.disabled,
          );
          btn?.focus();
        }}
        onChange={(e) => {
          onAdd(toItems(e.target.files));
          e.target.value = "";
        }}
      />
      {comfortable && (
        // Phones: no dragging, so the camera is the main way in and the picker sits under it.
        <div className="hidden flex-col gap-2 touch:flex" data-testid="uploader-touch">
          <Button type="button" size="xl" className="h-auto min-h-14 w-full whitespace-normal rounded-full py-3 text-lg" aria-describedby={shownError ? errorId : undefined} onClick={() => camRef.current?.click()}>
            <Camera aria-hidden /> {tx.takePhoto}
          </Button>
          <Button type="button" variant="outline" size="lg" className="h-auto min-h-12 w-full whitespace-normal rounded-full py-2.5" aria-describedby={shownError ? errorId : undefined} onClick={() => fileRef.current?.click()}>
            <UploadCloud aria-hidden /> {tx.chooseFile}
          </Button>
          <span className="text-center text-sm text-muted-foreground">{tx.limits}</span>
        </div>
      )}
      {/* Drop target only (no role): a real button inside opens the picker, so keyboards and screen readers get a plain button. */}
      <div
        data-testid="uploader-dropzone"
        onClick={(e) => {
          // Mouse convenience: a click anywhere on the zone opens the picker too.
          if (!(e.target as HTMLElement).closest("button")) fileRef.current?.click();
        }}
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
          "flex cursor-pointer flex-col items-center gap-2 rounded-lg border-2 border-dashed bg-muted/50 px-4 py-7 text-center transition-colors",
          over ? "border-primary bg-accent" : "border-input hover:bg-muted",
          shownError && "border-danger bg-danger-soft/50",
          comfortable && "touch:hidden",
        )}
      >
        <UploadCloud className="h-8 w-8 text-muted-foreground" aria-hidden />
        <span className={comfortable ? "text-base font-medium" : "text-sm font-medium"}>
          {/* Touch screens cannot drag files in: no "drag here" there, just the picker. */}
          <span className="touch:hidden">{tx.dropPrefix} </span>
          <button
            type="button"
            className="rounded-sm font-semibold text-primary underline underline-offset-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
            aria-describedby={describedBy}
            onClick={() => fileRef.current?.click()}
          >
            <span className="touch:hidden">{tx.browse}</span>
            <span className="hidden touch:inline">{tx.chooseFile}</span>
          </button>
        </span>
        <span id={hintId} className={comfortable ? "text-sm text-muted-foreground" : "text-xs text-muted-foreground"}>
          {tx.limits}
        </span>
      </div>
      <div className={cn("flex flex-wrap items-center gap-2", comfortable && "touch:hidden")}>
        <Button type="button" variant="outline" size={comfortable ? "lg" : "sm"} className={comfortable ? "h-auto min-h-11 whitespace-normal rounded-full py-2" : undefined} onClick={() => camRef.current?.click()}>
          <Camera aria-hidden /> {tx.takePhoto}
        </Button>
        <span className={comfortable ? "text-sm text-muted-foreground" : "text-xs text-muted-foreground"}>{tx.cameraHint}</span>
      </div>
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
      {shownError && (
        <p id={errorId} role="alert" className={cn("font-medium text-danger-foreground", comfortable ? "text-sm" : "text-xs")}>
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
                          {k.label}
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
