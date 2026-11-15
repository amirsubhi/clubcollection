import type { ReactNode } from "react";
import { ArrowLeft, Home, Landmark, PieChart, UserRound } from "lucide-react";
import { Link } from "@inertiajs/react";
import { useLang, useT, type DictKey } from "@/lib/i18n";
import { cn } from "@/lib/utils";
import { useStickyFit } from "@/lib/useStickyFit";

export type MemberTab = "home" | "payments" | "fund" | "account";

const tabs: { id: MemberTab; label: DictKey; Icon: typeof Home }[] = [
  { id: "home", label: "navHome", Icon: Home },
  { id: "payments", label: "navPayments", Icon: Landmark },
  { id: "fund", label: "navFund", Icon: PieChart },
  { id: "account", label: "navAccount", Icon: UserRound },
];

export function LangToggle() {
  const { lang, setLang } = useLang();
  return (
    <div role="radiogroup" aria-label="Language / Bahasa" className="inline-flex rounded-full border bg-card p-0.5 text-sm font-bold">
      {(["bm", "en"] as const).map((l) => (
        <button
          key={l}
          type="button"
          role="radio"
          aria-checked={lang === l}
          onClick={() => setLang(l)}
          className={cn(
            "inline-flex h-11 min-w-11 items-center justify-center rounded-full px-2.5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
            lang === l ? "bg-primary text-primary-foreground" : "text-muted-foreground hover:text-foreground",
          )}
        >
          {l.toUpperCase()}
        </button>
      ))}
    </div>
  );
}

export function ClubMark({ className }: { className?: string }) {
  return (
    <span className={cn("flex h-11 w-11 shrink-0 items-center justify-center rounded-[14px] bg-primary", className)} aria-hidden>
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

export function MemberShell({
  clubName,
  tab,
  navHrefs,
  title,
  backHref,
  children,
  hideNav = false,
  bare = false,
}: {
  /** Short club name shown above the page title. */
  clubName: string;
  tab: MemberTab;
  /** URL for each bottom-nav tab; tabs without a URL are not shown. */
  navHrefs: Partial<Record<MemberTab, string>>;
  title?: string;
  backHref?: string;
  children: ReactNode;
  hideNav?: boolean;
  /** Skip the page padding (for screens that draw their own full-bleed layout). */
  bare?: boolean;
}) {
  const t = useT();
  // Pinned while it is one compact row; with large text it scrolls away with the page.
  const [headerRef, headerPinned] = useStickyFit<HTMLElement>("top", { maxFraction: 0.15 });
  const [navRef] = useStickyFit<HTMLElement>("bottom", { keepPinned: true });
  const visibleTabs = tabs.filter((x) => navHrefs[x.id]);
  return (
    <div className="member-scope flex min-h-full flex-col text-base">
      <header
        ref={headerRef}
        className={cn("bleed-x relative z-20 border-b bg-background/90 py-2.5 backdrop-blur supports-[backdrop-filter]:bg-background/80", headerPinned && "sticky top-0")}
        data-pinned={headerPinned}
      >
        {/* Wraps with large text: the language switch drops under the title instead of squeezing it. */}
        <div className="mx-auto flex max-w-md flex-wrap items-center gap-x-2.5 gap-y-2">
          {backHref ? (
            <Link
              href={backHref}
              className="-ml-1.5 inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-full hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              aria-label={t("back")}
            >
              <ArrowLeft className="h-6 w-6" strokeWidth={1.75} />
            </Link>
          ) : (
            <ClubMark />
          )}
          <div className="min-w-0 flex-[1_1_8rem]">
            <div className="break-words text-sm leading-snug text-muted-foreground">{clubName}</div>
            <div className="break-words font-display text-lg font-bold leading-tight tracking-tight">{title ?? t("navHome")}</div>
          </div>
          <LangToggle />
        </div>
      </header>
      <main className={cn("flex-1", !bare && "py-5", !hideNav && "pb-32")}>
        <div className="mx-auto flex max-w-md flex-col gap-5">{children}</div>
      </main>
      {!hideNav && (
        <nav ref={navRef} aria-label="Member" className="safe-x pb-safe-nav fixed inset-x-0 bottom-0 z-30 border-t bg-card/95 pt-1.5 backdrop-blur">
          <ul className="mx-auto grid max-w-md gap-1" style={{ gridTemplateColumns: `repeat(${visibleTabs.length}, minmax(0, 1fr))` }}>
            {visibleTabs.map(({ id, label, Icon }) => {
              const active = id === tab;
              return (
                <li key={id}>
                  <Link
                    href={navHrefs[id]!}
                    aria-current={active ? "page" : undefined}
                    className={cn(
                      "flex min-h-14 flex-col items-center justify-center gap-1 rounded-2xl text-center text-[0.8125rem] font-semibold leading-tight [overflow-wrap:anywhere] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                      active ? "text-primary" : "text-muted-foreground hover:text-foreground",
                    )}
                  >
                    <span className={cn("flex h-8 w-full max-w-14 items-center justify-center rounded-full transition-colors", active && "bg-accent")}>
                      <Icon className="h-[22px] w-[22px]" aria-hidden strokeWidth={active ? 2.2 : 1.75} />
                    </span>
                    {t(label)}
                  </Link>
                </li>
              );
            })}
          </ul>
        </nav>
      )}
    </div>
  );
}
