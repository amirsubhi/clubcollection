import { File, FileImage, FileText } from "lucide-react";
import { cn } from "@/lib/utils";

/** "JPG", "PDF", "PNG"…: from the MIME type, else the file extension. */
export function fileTypeLabel(name: string, mime?: string): string {
  const fromMime = mime?.split("/")[1]?.split(/[+.;-]/)[0];
  const ext = name.includes(".") ? name.split(".").pop() : undefined;
  const raw = (fromMime && fromMime !== "octet" ? fromMime : ext) ?? "";
  return (raw === "jpeg" ? "jpg" : raw).toUpperCase() || "FILE";
}

export function fileSizeLabel(sizeKb: number): string {
  return sizeKb >= 1024 ? `${(sizeKb / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(sizeKb))} KB`;
}

/**
 * Shown when a document can't be previewed (no URL, or not an image): a neutral
 * file icon with its type and size, drawn with the theme tokens. It never
 * imitates a real document, so it can't be mistaken for one.
 */
export function AttachmentArt({ name, mime, sizeKb, className, title }: { name: string; mime?: string; sizeKb?: number; className?: string; title?: string }) {
  const type = fileTypeLabel(name, mime);
  const Icon = mime?.startsWith("image/") ? FileImage : type === "PDF" ? FileText : File;
  const size = sizeKb ? fileSizeLabel(sizeKb) : null;
  return (
    <div
      className={cn("flex flex-col items-center justify-center gap-1.5 bg-muted p-3 text-center text-muted-foreground", className)}
      role="img"
      aria-label={`${title ? `${title}: ` : ""}${type} file${size ? `, ${size}` : ""}`}
    >
      <Icon className="h-10 w-10" strokeWidth={1.5} aria-hidden />
      <span className="text-xs font-bold text-foreground">{type}</span>
      {size && <span className="text-xs">{size}</span>}
    </div>
  );
}
