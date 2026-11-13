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
    <div role="radiogroup" aria-label="Language / Bahasa" className="inline-flex rounded-md border bg-card p-0.5 text-xs font-semibold">
      {(["bm", "en"] as const).map((l) => (
        <button
          key={l}
          type="button"
          role="radio"
          aria-checked={lang === l}
          onClick={() => setLang(l)}
          className={cn(
            "rounded px-2 py-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
            lang === l ? "bg-primary text-primary-foreground" : "text-muted-foreground hover:text-foreground",
          )}
        >
          {l.toUpperCase()}
        </button>
      ))}
    </div>
  );
}

export function MemberShell({
  tab,
  title,
  backHash,
  children,
  hideNav = false,
}: {
  tab: MemberTab;
  title?: string;
  backHash?: string;
  children: ReactNode;
  hideNav?: boolean;
}) {
  const t = useT();
  return (
    <div className="flex min-h-full flex-col">
      <MockupStrip />
      <header className="bleed-x sticky top-0 z-20 border-b bg-card/95 py-2.5 backdrop-blur">
        <div className="mx-auto flex max-w-md items-center gap-2">
          {backHash ? (
            <a href={`#${backHash}`} className="-ml-1 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md hover:bg-muted" aria-label={t("back")}>
              <ArrowLeft className="h-5 w-5" />
            </a>
          ) : (
            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-primary font-display text-sm font-bold text-primary-foreground" aria-hidden>
              KC
            </div>
          )}
          <div className="min-w-0 flex-1">
            <div className="truncate text-xs text-muted-foreground">{club.name}</div>
            <div className="truncate font-display text-base font-semibold leading-tight">{title ?? club.shortName}</div>
          </div>
          <LangToggle />
        </div>
      </header>
      <main className={cn("flex-1 py-4", !hideNav && "pb-28")}>
        <div className="mx-auto flex max-w-md flex-col gap-4">{children}</div>
      </main>
      {!hideNav && (
        <nav aria-label="Member" className="safe-x pb-safe-nav fixed inset-x-0 bottom-0 z-30 border-t bg-card pt-1.5">
          <ul className="mx-auto grid max-w-md grid-cols-4">
            {tabs.map(({ id, hash, label, Icon }) => {
              const active = id === tab;
              return (
                <li key={id}>
                  <a
                    href={`#${hash}`}
                    aria-current={active ? "page" : undefined}
                    className={cn(
                      "flex flex-col items-center gap-0.5 rounded-md py-1.5 text-[11px] font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                      active ? "text-primary" : "text-muted-foreground hover:text-foreground",
                    )}
                  >
                    <Icon className="h-5 w-5" aria-hidden strokeWidth={active ? 2.4 : 1.8} />
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
