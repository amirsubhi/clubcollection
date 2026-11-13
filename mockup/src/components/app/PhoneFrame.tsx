import type { ReactNode } from "react";

/**
 * Desktop-only 390px phone preview. The transform makes `position: fixed` children (bottom nav)
 * stick to the frame instead of the browser window.
 */
export function PhoneFrame({ children, onExit }: { children: ReactNode; onExit: () => void }) {
  return (
    <div className="flex min-h-screen flex-col items-center gap-3 bg-slate-200 px-4 py-5">
      <div className="flex items-center gap-3 text-xs text-slate-700">
        <span className="font-medium">Phone preview · 390 × 844</span>
        <button type="button" onClick={onExit} className="rounded border border-slate-400 bg-white px-2 py-0.5 font-medium hover:bg-slate-50">
          Show full width
        </button>
        <a href="#overview" className="underline">
          All screens
        </a>
      </div>
      <div
        className="relative w-[390px] overflow-hidden rounded-[2.25rem] border-[10px] border-slate-900 bg-background shadow-2xl"
        style={{ height: "min(844px, calc(100vh - 80px))", transform: "translateZ(0)" }}
      >
        <div id="phone-scroll" className="h-full overflow-y-auto overflow-x-hidden">
          {children}
        </div>
      </div>
    </div>
  );
}
