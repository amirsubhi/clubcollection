import type { ReactNode } from "react";
import { ArrowLeft, ChevronDown, Home, Landmark, Languages, PieChart, UserRound } from "lucide-react";
import { Link } from "@inertiajs/react";
import { LANGS, langMeta, useLang, useT, type DictKey } from "@/lib/i18n";
import { cn } from "@/lib/utils";
import { useStickyFit } from "@/lib/useStickyFit";

export type MemberTab = "home" | "payments" | "fund" | "account";

const tabs: { id: MemberTab; label: DictKey; Icon: typeof Home }[] = [
  { id: "home", label: "navHome", Icon: Home },
  { id: "payments", label: "navPayments", Icon: Landmark },
  { id: "fund", label: "navFund", Icon: PieChart },
  { id: "account", label: "navAccount", Icon: UserRound },
];

/**
 * The language picker: a native <select> (keyboard, screen readers and phone pickers for free) drawn as a compact
 * pill showing the current language's short label ("BM"); the options are every lang/member file, each in its own
 * name, "(beta)" for a first translation. Its name starts with the label it shows ("BM – Bahasa"). Choosing one
 * switches at once and saves in the background (app.tsx).
 */
export function LangToggle() {
  const t = useT();
  const { lang, setLang } = useLang();
  return (
    <label
      className="relative inline-flex h-11 min-w-[44px] shrink-0 cursor-pointer items-center gap-1 rounded-full border bg-card pl-2.5 pr-2 text-sm font-bold text-foreground focus-within:ring-2 focus-within:ring-ring hover:bg-muted"
      data-testid="lang-picker"
    >
      <Languages className="h-4 w-4 text-muted-foreground" aria-hidden strokeWidth={2} />
      <span aria-hidden>{langMeta(lang).label}</span>
      <ChevronDown className="h-4 w-4 text-muted-foreground" aria-hidden strokeWidth={2} />
      <select
        aria-label={`${langMeta(lang).label} – ${t("langPicker")}`}
        value={lang}
        onChange={(e) => setLang(e.target.value)}
        className="absolute inset-0 h-full w-full cursor-pointer appearance-none rounded-full opacity-0"
      >
        {LANGS.map((l) => {
          const meta = langMeta(l);
          return (
            <option key={l} value={l} lang={meta.htmlLang}>
              {meta.beta ? `${meta.name} (beta)` : meta.name}
            </option>
          );
        })}
      </select>
    </label>
  );
}

/**
 * The club's logo (Club profile) on a white tile, so a dark or transparent logo stays visible in dark mode; the
 * portal's built-in mark when there is none. alt = the club's name.
 */
export function ClubMark({ className, src, alt }: { className?: string; src?: string | null; alt?: string }) {
  if (src) {
    return (
      <span className={cn("flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-[14px] border bg-white p-0.5", className)}>
        <img src={src} alt={alt ?? ""} className="max-h-full max-w-full object-contain" />
      </span>
    );
  }
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
  clubLogoUrl,
  tab,
  navHrefs,
  title,
  backHref,
  children,
  hideNav = false,
  bare = false,
  titleIsHeading = true,
}: {
  /** Short club name shown above the page title. */
  clubName: string;
  /** The club's logo (Club profile), shown instead of the built-in mark on pages without a back button. */
  clubLogoUrl?: string | null;
  tab: MemberTab;
  /** URL for each bottom-nav tab; tabs without a URL are not shown. */
  navHrefs: Partial<Record<MemberTab, string>>;
  title?: string;
  backHref?: string;
  children: ReactNode;
  hideNav?: boolean;
  /** Skip the page padding (for screens that draw their own full-bleed layout). */
  bare?: boolean;
  /** The header title is the page's <h1>. Pass false when the page draws its own h1 (PayResult's big status titles). */
  titleIsHeading?: boolean;
}) {
  const t = useT();
  // Pinned while it is one compact row; with large text it scrolls away with the page.
  const [headerRef, headerPinned] = useStickyFit<HTMLElement>("top", { maxFraction: 0.15 });
  const [navRef] = useStickyFit<HTMLElement>("bottom", { keepPinned: true });
  const visibleTabs = tabs.filter((x) => navHrefs[x.id]);
  return (
    <div className="member-scope flex min-h-full flex-col text-base">
      <a
        href="#main"
        className="sr-only z-[60] rounded-full bg-primary px-5 py-3 text-base font-semibold text-primary-foreground focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:px-5 focus:py-3 focus:shadow-lg focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
      >
        {t("skipToContent")}
      </a>
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
            <ClubMark src={clubLogoUrl} alt={clubName} />
          )}
          <div className="min-w-0 flex-[1_1_8rem]">
            <div className="break-words text-sm leading-snug text-muted-foreground">{clubName}</div>
            {titleIsHeading ? (
              <h1 className="break-words font-display text-lg font-bold leading-tight tracking-tight">{title ?? t("navHome")}</h1>
            ) : (
              <div className="break-words font-display text-lg font-bold leading-tight tracking-tight">{title ?? t("navHome")}</div>
            )}
          </div>
          <LangToggle />
        </div>
      </header>
      <main id="main" tabIndex={-1} className={cn("flex-1 focus:outline-none", !bare && "py-5", !hideNav && "pb-32")}>
        <div className="mx-auto flex max-w-md flex-col gap-5">{children}</div>
      </main>
      {!hideNav && (
        <nav ref={navRef} aria-label={t("memberNav")} className="safe-x pb-safe-nav fixed inset-x-0 bottom-0 z-30 border-t bg-card/95 pt-1.5 backdrop-blur">
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
                    <span className={cn("flex h-8 w-full max-w-[56px] items-center justify-center rounded-full transition-colors", active && "bg-accent")}>
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
