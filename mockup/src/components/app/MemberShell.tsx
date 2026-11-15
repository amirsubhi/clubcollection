import type { ReactNode } from "react";
import { ArrowLeft, Home, Landmark, PieChart, UserRound } from "lucide-react";
import { MockupStrip } from "./MockupStrip";
import { club } from "@/data/club";
import { useLang, useT, type DictKey } from "@/i18n";
import { cn } from "@/lib/utils";

export type MemberTab = "home" | "payments" | "fund" | "account";

const tabs: { id: MemberTab; hash: string; label: DictKey; Icon: typeof Home }[] = [
  { id: "home", hash: "member-home", label: "navHome", Icon: Home },
  { id: "payments", hash: "member-history", label: "navPayments", Icon: Landmark },
  { id: "fund", hash: "member-fund", label: "navFund", Icon: PieChart },
  { id: "account", hash: "member-account", label: "navAccount", Icon: UserRound },
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
            "inline-flex h-10 min-w-11 items-center justify-center rounded-full px-2.5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
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
  tab,
  title,
  backHash,
  children,
  hideNav = false,
  bare = false,
}: {
  tab: MemberTab;
  title?: string;
  backHash?: string;
  children: ReactNode;
  hideNav?: boolean;
  /** Skip the page padding (for screens that draw their own full-bleed layout). */
  bare?: boolean;
}) {
  const t = useT();
  return (
    <div className="member-scope flex min-h-full flex-col text-base">
      <MockupStrip />
      <header className="bleed-x sticky top-0 z-20 border-b bg-background/90 py-2.5 backdrop-blur supports-[backdrop-filter]:bg-background/80">
        <div className="mx-auto flex max-w-md items-center gap-2.5">
          {backHash ? (
            <a
              href={`#${backHash}`}
              className="-ml-1.5 inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-full hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              aria-label={t("back")}
            >
              <ArrowLeft className="h-6 w-6" strokeWidth={1.75} />
            </a>
          ) : (
            <ClubMark />
          )}
          <div className="min-w-0 flex-1">
            <div className="truncate text-sm text-muted-foreground">{club.shortName}</div>
            <div className="truncate font-display text-lg font-bold leading-tight tracking-tight">{title ?? t("navHome")}</div>
          </div>
          <LangToggle />
        </div>
      </header>
      <main className={cn("flex-1", !bare && "py-5", !hideNav && "pb-32")}>
        <div className="mx-auto flex max-w-md flex-col gap-5">{children}</div>
      </main>
      {!hideNav && (
        <nav aria-label="Member" className="safe-x pb-safe-nav fixed inset-x-0 bottom-0 z-30 border-t bg-card/95 pt-1.5 backdrop-blur">
          <ul className="mx-auto grid max-w-md grid-cols-4 gap-1">
            {tabs.map(({ id, hash, label, Icon }) => {
              const active = id === tab;
              return (
                <li key={id}>
                  <a
                    href={`#${hash}`}
                    aria-current={active ? "page" : undefined}
                    className={cn(
                      "flex min-h-14 flex-col items-center justify-center gap-1 rounded-2xl text-[13px] font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                      active ? "text-primary" : "text-muted-foreground hover:text-foreground",
                    )}
                  >
                    <span className={cn("flex h-8 w-14 items-center justify-center rounded-full transition-colors", active && "bg-accent")}>
                      <Icon className="h-[22px] w-[22px]" aria-hidden strokeWidth={active ? 2.2 : 1.75} />
                    </span>
                    {t(label)}
                  </a>
                </li>
              );
            })}
          </ul>
        </nav>
      )}
    </div>
  );
}
