import type { ReactNode } from "react";

/**
 * Desktop-only 390px phone preview. The transform makes `position: fixed` children (bottom nav)
 * stick to the frame instead of the browser window.
 */
export function PhoneFrame({ children, onExit }: { children: ReactNode; onExit: () => void }) {
  return (
    <div className="flex min-h-screen flex-col items-center gap-3 bg-secondary px-4 py-5">
      <div className="flex items-center gap-3 text-xs text-muted-foreground">
        <span className="font-medium">Phone preview · 390 × 844</span>
        <button type="button" onClick={onExit} className="min-h-8 rounded-md border bg-card px-2.5 font-semibold text-foreground hover:bg-muted">
          Show full width
        </button>
        <a href="#overview" className="font-semibold text-foreground underline underline-offset-2">
          All screens
        </a>
      </div>
      <div
        className="relative w-[390px] overflow-hidden rounded-[2.5rem] border-[10px] border-[#0b1210] bg-background shadow-2xl"
        style={{ height: "min(844px, calc(100vh - 80px))", transform: "translateZ(0)" }}
      >
        <div id="phone-scroll" className="h-full overflow-y-auto overflow-x-hidden">
          {children}
        </div>
      </div>
    </div>
  );
}
