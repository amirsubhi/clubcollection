import { useState, type ReactNode } from "react";
import { Link } from "@inertiajs/react";
import { LogOut, Menu, Monitor, Moon, PanelLeft, Sun, UserRound } from "lucide-react";
import { Avatar } from "./Visuals";
import { Button } from "@/components/ui/button";
import { Sheet, SheetContent, SheetDescription, SheetTitle, SheetTrigger } from "@/components/ui/sheet";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { readPref, writePref } from "@/lib/storage";
import { useTheme, type ThemePref } from "@/lib/theme";
import { cn } from "@/lib/utils";

export interface AdminNavItem {
  /** Stable id; compared with the shell's `active` prop. */
  key: string;
  label: string;
  href?: string;
  Icon: typeof Menu;
  badge?: number;
  disabled?: boolean;
  /** Page not on Inertia yet (Blade): use a full page load, not an Inertia visit. */
  external?: boolean;
}

export interface AdminNavGroup {
  title: string;
  items: AdminNavItem[];
}

function Logo({ className }: { className?: string }) {
  return (
    <span className={cn("relative flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-[10px] bg-primary", className)} aria-hidden>
      <svg viewBox="0 0 36 36" className="h-9 w-9">
        <g stroke="rgb(var(--primary-foreground))" strokeOpacity="0.5" strokeWidth="1.5">
          <line x1="10" y1="12" x2="24" y2="10" />
          <line x1="10" y1="12" x2="14" y2="25" />
          <line x1="24" y1="10" x2="26" y2="24" />
          <line x1="14" y1="25" x2="26" y2="24" />
        </g>
        <circle cx="10" cy="12" r="3" fill="rgb(var(--primary-foreground))" fillOpacity="0.8" />
        <circle cx="24" cy="10" r="2.5" fill="rgb(var(--primary-foreground))" fillOpacity="0.8" />
        <circle cx="14" cy="25" r="2.5" fill="rgb(var(--primary-foreground))" fillOpacity="0.8" />
        <circle cx="26" cy="24" r="3.5" fill="rgb(var(--primary-foreground))" />
      </svg>
    </span>
  );
}

function Brand({ collapsed, href, subtitle }: { collapsed?: boolean; href: string; subtitle?: string }) {
  return (
    <Link href={href} className="flex min-h-11 items-center gap-2.5 rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
      <Logo />
      {!collapsed && (
        <span className="min-w-0">
          <span className="block font-display text-[15px] font-extrabold leading-tight tracking-tight">Club Portal</span>
          {subtitle && <span className="block truncate text-xs text-muted-foreground">{subtitle}</span>}
        </span>
      )}
    </Link>
  );
}

function NavList({ groups, active, collapsed }: { groups: AdminNavGroup[]; active: string; collapsed?: boolean }) {
  return (
    <nav aria-label="Admin" className="flex flex-col gap-5">
      {groups.map((g) => (
        <div key={g.title}>
          {collapsed ? (
            <div className="mx-auto mb-2 h-px w-6 bg-border" aria-hidden />
          ) : (
            <div className="mb-1.5 px-2.5 text-xs font-semibold text-muted-foreground">{g.title}</div>
          )}
          <ul className="flex flex-col gap-0.5">
            {g.items.map((it) => {
              const isActive = it.key === active;
              const cls = cn(
                "relative flex h-10 items-center gap-2.5 rounded-md px-2.5 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                collapsed && "justify-center px-0",
                isActive ? "bg-accent text-accent-foreground" : "text-foreground/85 hover:bg-muted hover:text-foreground",
                it.disabled && "cursor-not-allowed text-muted-foreground hover:bg-transparent hover:text-muted-foreground",
              );
              const inner = (
                <>
                  {isActive && <span className="absolute -left-3 top-2 h-6 w-1 rounded-r-full bg-primary" aria-hidden />}
                  <it.Icon className="h-[18px] w-[18px] shrink-0" strokeWidth={1.75} aria-hidden />
                  {!collapsed && <span className="truncate">{it.label}</span>}
                  {!collapsed && it.disabled && <span className="ml-auto text-[11px]">soon</span>}
                  {it.badge ? (
                    collapsed ? (
                      <span className="absolute right-1.5 top-1.5 h-2 w-2 rounded-full bg-primary" aria-label={`${it.badge} waiting`} />
                    ) : (
                      <span className="ml-auto inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary px-1.5 text-[11px] font-bold text-primary-foreground">{it.badge}</span>
                    )
                  ) : null}
                </>
              );
              const el = it.disabled || !it.href ? (
                <span className={cls} title="Not available yet" aria-disabled>
                  {inner}
                </span>
              ) : it.external ? (
                <a href={it.href} aria-label={collapsed ? it.label : undefined} className={cls}>
                  {inner}
                </a>
              ) : (
                <Link href={it.href} aria-current={isActive ? "page" : undefined} aria-label={collapsed ? it.label : undefined} className={cls}>
                  {inner}
                </Link>
              );
              return (
                <li key={it.key}>
                  {collapsed ? (
                    <Tooltip>
                      <TooltipTrigger asChild>{el}</TooltipTrigger>
                      <TooltipContent side="right">{it.label}</TooltipContent>
                    </Tooltip>
                  ) : (
                    el
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

const themeOpts: { value: ThemePref; label: string; Icon: typeof Sun }[] = [
  { value: "light", label: "Light", Icon: Sun },
  { value: "dark", label: "Dark", Icon: Moon },
  { value: "system", label: "System", Icon: Monitor },
];

export function ThemeMenu() {
  const { theme, setTheme } = useTheme();
  const Cur = themeOpts.find((o) => o.value === theme)?.Icon ?? Monitor;
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" size="icon" aria-label={`Theme: ${theme}`}>
          <Cur className="h-[18px] w-[18px]" strokeWidth={1.75} />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="min-w-[9rem]">
        <DropdownMenuLabel>Theme</DropdownMenuLabel>
        {themeOpts.map(({ value, label, Icon }) => (
          <DropdownMenuItem key={value} onSelect={() => setTheme(value)} className={cn(theme === value && "font-semibold text-primary")}>
            <Icon strokeWidth={1.75} /> {label}
            {theme === value && <span className="ml-auto text-xs">✓</span>}
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

function UserMenu({ user, memberHref, onLogout }: { user: { name: string; roleLabel?: string }; memberHref?: string; onLogout?: () => void }) {
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <button type="button" className="inline-flex h-10 w-10 items-center justify-center rounded-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring" aria-label="Account menu">
          <Avatar name={user.name} />
        </button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-60">
        <div className="flex items-center gap-2.5 px-2 py-2">
          <Avatar name={user.name} />
          <div className="min-w-0">
            <div className="truncate text-sm font-semibold">{user.name}</div>
            {user.roleLabel && <div className="truncate text-xs text-muted-foreground">{user.roleLabel}</div>}
          </div>
        </div>
        {memberHref && (
          <>
            <DropdownMenuSeparator />
            <DropdownMenuItem asChild>
              <Link href={memberHref}>
                <UserRound strokeWidth={1.75} /> Member view
              </Link>
            </DropdownMenuItem>
          </>
        )}
        {onLogout && (
          <>
            <DropdownMenuSeparator />
            <DropdownMenuItem onSelect={onLogout}>
              <LogOut strokeWidth={1.75} /> Log out
            </DropdownMenuItem>
          </>
        )}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

export interface AdminShellProps {
  /** Key of the active nav item. */
  active: string;
  nav: AdminNavGroup[];
  user: { name: string; roleLabel?: string };
  /** Where the logo links to (admin landing). */
  homeHref: string;
  /** Second line under "Club Portal", e.g. "KSR · Bendahari". */
  brandSubtitle?: string;
  /** Optional search or other control placed in the top bar. */
  search?: ReactNode;
  /** Optional card at the bottom of the sidebar. */
  sidebarFooter?: ReactNode;
  memberHref?: string;
  onLogout?: () => void;
  children: ReactNode;
  wide?: boolean;
}

export function AdminShell({ active, nav, user, homeHref, brandSubtitle, search, sidebarFooter, memberHref, onLogout, children, wide = true }: AdminShellProps) {
  const [collapsed, setCollapsed] = useState(() => readPref("cp-sidebar") === "collapsed");
  const toggle = () => {
    setCollapsed((c) => {
      writePref("cp-sidebar", c ? "expanded" : "collapsed");
      return !c;
    });
  };
  return (
    <div className="flex min-h-full flex-col">
      <div className="bleed-x-flush flex flex-1">
        {/* Outer aside stretches to the full page height (so the sidebar colour never stops short); inner column stays sticky. */}
        <aside className={cn("hidden shrink-0 self-stretch border-r bg-sidebar transition-[width] duration-200 lg:block", collapsed ? "w-[68px]" : "w-64")}>
          <div className={cn("sticky top-0 flex h-screen flex-col gap-6 py-4", collapsed ? "px-2" : "px-3")}>
            <div className={cn(collapsed ? "flex justify-center" : "px-1")}>
              <Brand collapsed={collapsed} href={homeHref} subtitle={brandSubtitle} />
            </div>
            <div className="-mr-1 flex-1 overflow-y-auto pr-1">
              <NavList groups={nav} active={active} collapsed={collapsed} />
            </div>
            {!collapsed && sidebarFooter}
          </div>
        </aside>
        <div className="flex min-w-0 flex-1 flex-col">
          <header className="safe-x sticky top-0 z-20 flex h-14 items-center gap-2 border-b bg-background/90 backdrop-blur supports-[backdrop-filter]:bg-background/75">
            <Sheet>
              <SheetTrigger asChild>
                <Button variant="ghost" size="icon" className="shrink-0 lg:hidden" aria-label="Open menu">
                  <Menu className="h-5 w-5" />
                </Button>
              </SheetTrigger>
              <SheetContent side="left" className="bg-sidebar">
                <SheetTitle className="sr-only">Menu</SheetTitle>
                <SheetDescription className="sr-only">Admin navigation</SheetDescription>
                <div className="px-1 pr-10">
                  <Brand href={homeHref} subtitle={brandSubtitle} />
                </div>
                <div className="pl-3">
                  <NavList groups={nav} active={active} />
                </div>
              </SheetContent>
            </Sheet>
            <Button variant="ghost" size="icon" className="hidden shrink-0 lg:inline-flex" onClick={toggle} aria-label={collapsed ? "Expand sidebar" : "Collapse sidebar"} aria-pressed={collapsed}>
              <PanelLeft className="h-[18px] w-[18px]" strokeWidth={1.75} />
            </Button>
            <div className="mx-1 hidden h-5 w-px bg-border lg:block" aria-hidden />
            {search ?? <div className="flex-1" />}
            <div className="ml-auto flex shrink-0 items-center gap-1">
              <ThemeMenu />
              <UserMenu user={user} memberHref={memberHref} onLogout={onLogout} />
            </div>
          </header>
          <main className="safe-x flex-1 py-6 lg:py-8">
            <div className={cn("mx-auto flex flex-col gap-6", wide ? "max-w-7xl" : "max-w-3xl")}>{children}</div>
          </main>
        </div>
      </div>
    </div>
  );
}
