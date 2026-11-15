import { LayoutGrid } from "lucide-react";

export function MockupStrip({ showIndexLink = true }: { showIndexLink?: boolean }) {
  return (
    <div className="bleed-x flex min-h-8 items-center justify-between gap-3 bg-strip py-0 text-xs text-strip-foreground">
      <span className="inline-flex items-center gap-2 font-medium">
        <span className="h-1.5 w-1.5 rounded-full bg-marigold" aria-hidden />
        Mockup — sample data <span className="hidden opacity-80 sm:inline">· not live, nothing is saved</span>
      </span>
      {showIndexLink && (
        <a href="#overview" className="inline-flex min-h-8 items-center gap-1 rounded px-1.5 text-strip-foreground underline-offset-2 hover:underline">
          <LayoutGrid className="h-3.5 w-3.5" aria-hidden /> All screens
        </a>
      )}
    </div>
  );
}
