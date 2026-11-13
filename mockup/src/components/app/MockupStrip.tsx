import { LayoutGrid } from "lucide-react";

export function MockupStrip({ showIndexLink = true }: { showIndexLink?: boolean }) {
  return (
    <div className="bleed-x flex min-h-8 items-center justify-between gap-3 bg-slate-800 py-1 text-xs text-slate-100">
      <span className="font-medium">
        Mockup — sample data <span className="hidden text-slate-300 sm:inline">· not live, nothing is saved</span>
      </span>
      {showIndexLink && (
        <a href="#overview" className="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-slate-100 underline-offset-2 hover:underline">
          <LayoutGrid className="h-3.5 w-3.5" aria-hidden /> All screens
        </a>
      )}
    </div>
  );
}
