import type { ReactNode } from "react";
import { ClipboardCheck, FilePlus2, FileText, HandHeart, HelpCircle, LayoutDashboard, ListChecks, Menu, Smartphone, Users, Wallet } from "lucide-react";
import { MockupStrip } from "./MockupStrip";
import { Button } from "@/components/ui/button";
import { Sheet, SheetContent, SheetDescription, SheetTitle, SheetTrigger } from "@/components/ui/sheet";
import { club, currentAdmin } from "@/data/club";
import { awaitingMyApproval } from "@/data/fund";
import { cn } from "@/lib/utils";

interface NavItem {
  hash?: string;
  label: string;
  Icon: typeof Menu;
  badge?: number;
  disabled?: boolean;
}

const sections: { title: string; items: NavItem[] }[] = [
  {
    title: "Club",
    items: [
      { label: "Dashboard", Icon: LayoutDashboard, disabled: true },
      { label: "Members", Icon: Users, disabled: true },
      { label: "Payments", Icon: Wallet, disabled: true },
    ],
  },
  {
    title: "Fund Usage",
    items: [
      { hash: "fund-usage", label: "All records", Icon: ListChecks, badge: awaitingMyApproval.length },
      { hash: "fund-new", label: "New expense", Icon: FilePlus2 },
      { hash: "welfare-new", label: "New welfare case", Icon: HandHeart },
      { hash: "fund-item", label: "Record detail", Icon: ClipboardCheck },
      { hash: "fund-approve", label: "Approve on phone", Icon: Smartphone },
    ],
  },
  {
    title: "Reports",
    items: [
      { hash: "agm-report", label: "AGM report", Icon: FileText },
      { hash: "questions", label: "Questions for committee", Icon: HelpCircle },
    ],
  },
];

function NavList({ active }: { active: string }) {
  return (
    <nav aria-label="Admin" className="flex flex-col gap-5">
      {sections.map((s) => (
        <div key={s.title}>
          <div className="mb-1.5 px-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{s.title}</div>
          <ul className="flex flex-col gap-0.5">
            {s.items.map((it) => {
              const isActive = it.hash === active;
              const cls = cn(
                "flex items-center gap-2.5 rounded-md px-2 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                isActive ? "bg-accent font-semibold text-accent-foreground" : "text-foreground hover:bg-muted",
                it.disabled && "cursor-not-allowed text-muted-foreground hover:bg-transparent",
              );
              return (
                <li key={it.label}>
                  {it.disabled ? (
                    <span className={cls} title="Not part of this mockup">
                      <it.Icon className="h-4 w-4" aria-hidden />
                      {it.label}
                      <span className="ml-auto text-[10px]">not in mockup</span>
                    </span>
                  ) : (
                    <a href={`#${it.hash}`} aria-current={isActive ? "page" : undefined} className={cls}>
                      <it.Icon className="h-4 w-4" aria-hidden />
                      {it.label}
                      {it.badge ? (
                        <span className="ml-auto rounded-full bg-warning-soft px-1.5 text-xs font-semibold text-warning-foreground">{it.badge}</span>
                      ) : null}
                    </a>
                  )}
                </li>
              );
            })}
          </ul>
        </div>
      ))}
    </nav>
  );
}

function Brand() {
  return (
    <div className="flex items-center gap-2.5">
      <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-primary font-display text-sm font-bold text-primary-foreground" aria-hidden>
        KC
      </div>
      <div className="min-w-0">
        <div className="font-display text-sm font-semibold leading-tight">Club Portal</div>
        <div className="truncate text-xs text-muted-foreground">{club.name}</div>
      </div>
    </div>
  );
}

export function AdminShell({ active, children, wide = true }: { active: string; children: ReactNode; wide?: boolean }) {
  return (
    <div className="flex min-h-full flex-col">
      <MockupStrip />
      <div className="bleed-x-flush flex flex-1">
        <aside className="hidden w-64 shrink-0 flex-col gap-6 border-r bg-card p-4 lg:flex">
          <Brand />
          <NavList active={active} />
          <div className="mt-auto rounded-md bg-muted p-2.5 text-xs">
            <div className="font-semibold">{currentAdmin.name}</div>
            <div className="text-muted-foreground">{currentAdmin.role}</div>
          </div>
        </aside>
        <div className="flex min-w-0 flex-1 flex-col">
          <header className="safe-x sticky top-0 z-20 flex items-center gap-3 border-b bg-card py-2 lg:hidden">
            <Sheet>
              <SheetTrigger asChild>
                <Button variant="outline" size="icon" aria-label="Open menu">
                  <Menu className="h-5 w-5" />
                </Button>
              </SheetTrigger>
              <SheetContent side="left">
                <SheetTitle className="sr-only">Menu</SheetTitle>
                <SheetDescription className="sr-only">Admin navigation</SheetDescription>
                <Brand />
                <NavList active={active} />
                <div className="mt-auto rounded-md bg-muted p-2.5 text-xs">
                  <div className="font-semibold">{currentAdmin.name}</div>
                  <div className="text-muted-foreground">{currentAdmin.role}</div>
                </div>
              </SheetContent>
            </Sheet>
            <Brand />
          </header>
          <main className={cn("safe-x flex-1 py-5 lg:py-7")}>
            <div className={cn("mx-auto flex flex-col gap-5", wide ? "max-w-6xl" : "max-w-3xl")}>{children}</div>
          </main>
        </div>
      </div>
    </div>
  );
}
