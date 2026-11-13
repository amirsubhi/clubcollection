import { rm } from "@/lib/format";
import { cn } from "@/lib/utils";
import type { Sen } from "@/data/types";

export function Money({ sen, className }: { sen: Sen; className?: string }) {
  return <span className={cn("money", className)}>{rm(sen)}</span>;
}
