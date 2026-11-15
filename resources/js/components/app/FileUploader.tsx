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
}

export interface FileUploaderProps {
  files: UploadItem[];
  onAdd: (files: UploadItem[]) => void;
  onRemove: (id: string) => void;
  onKindChange: (id: string, kind: AttachmentKind) => void;
  error?: string;
  maxFiles?: number;
}

function toItems(list: FileList | null): UploadItem[] {
  if (!list) return [];
  return Array.from(list).map((f, i) => ({
    id: `${Date.now()}-${i}-${f.name}`,
    name: f.name,
    sizeKb: Math.max(1, Math.round(f.size / 1024)),
    isPdf: f.type === "application/pdf" || f.name.toLowerCase().endsWith(".pdf"),
    kind: "",
  }));
}

/** Multi-file uploader: drag-drop or browse, camera capture on phones, per-file kind and remove. Upload is left to the parent form (e.g. Inertia useForm). */
export function FileUploader({ files, onAdd, onRemove, onKindChange, error, maxFiles = 10 }: FileUploaderProps) {
  const inputId = useId();
  const camRef = useRef<HTMLInputElement>(null);
  const [over, setOver] = useState(false);
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
          error && "border-danger bg-danger-soft/50",
        )}
      >
        <UploadCloud className="h-8 w-8 text-muted-foreground" aria-hidden />
        <span className="text-sm font-medium">
          Drag files here or <span className="text-primary underline">browse</span>
        </span>
        <span className="text-xs text-muted-foreground">JPG, PNG or PDF · up to 10 MB each · max {maxFiles} files</span>
        <input
          id={inputId}
          type="file"
          multiple
          accept="image/*,.pdf"
          className="sr-only"
          onChange={(e) => {
            onAdd(toItems(e.target.files));
            e.target.value = "";
          }}
        />
      </label>
      <div className="flex flex-wrap items-center gap-2">
        <Button type="button" variant="outline" size="sm" onClick={() => camRef.current?.click()}>
          <Camera aria-hidden /> Take photo
        </Button>
        <span className="text-xs text-muted-foreground">On a phone this opens the camera.</span>
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
      {error && <p className="text-xs font-medium text-danger-foreground">{error}</p>}
      {files.length > 0 && (
        <ul className="flex flex-col divide-y rounded-lg border">
          {files.map((f) => (
            <li key={f.id} className="flex flex-col gap-2 p-2.5 sm:flex-row sm:items-center">
              <div className="flex min-w-0 flex-1 items-center gap-2.5">
                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded bg-muted">
                  {f.isPdf ? <FileText className="h-5 w-5 text-danger" aria-hidden /> : <ImageIcon className="h-5 w-5 text-info" aria-hidden />}
                </div>
                <div className="min-w-0">
                  <div className="truncate text-sm font-medium">{f.name}</div>
                  <div className="text-xs text-muted-foreground">{f.sizeKb} KB</div>
                </div>
              </div>
              <div className="flex items-center gap-2">
                <Select value={f.kind || undefined} onValueChange={(v) => onKindChange(f.id, v as AttachmentKind)}>
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
                <Button type="button" variant="ghost" size="icon" className="h-9 w-9 shrink-0" onClick={() => onRemove(f.id)} aria-label={`Remove ${f.name}`}>
                  <Trash2 className="text-danger" />
                </Button>
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
