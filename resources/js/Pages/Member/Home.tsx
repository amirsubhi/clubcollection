import { AlertTriangle, CheckCircle2, ChevronRight, KeyRound, LogOut, ShieldCheck } from "lucide-react";
import { Head, Link, router, usePage } from "@inertiajs/react";
import { MemberShell } from "@/components/app/MemberShell";
import { FlashBanner, PaymentRow, SectionCard, toSlices } from "@/components/app/MemberParts";
import { Money } from "@/components/app/Money";
import { CategoryBar } from "@/components/app/CategoryBar";
import { ThemeToggle } from "@/components/app/ThemeToggle";
import { NodePattern } from "@/components/app/Visuals";
import { Button } from "@/components/ui/button";
import { rm } from "@/lib/format";
import { fill, useLang, useT } from "@/lib/i18n";
import type { HomeClub, MemberPaymentRow } from "@/types/member";

interface Props {
  year: number;
  outstanding: { total: number; count: number; overdueCount: number };
  upcoming: MemberPaymentRow[];
  recentPaid: MemberPaymentRow[];
  clubs: HomeClub[];
  dashboardUrl: string;
  account: { isAdmin: boolean; adminUrl: string | null; securityUrl: string; logoutUrl: string };
}

function Hero({ outstanding, next }: { outstanding: Props["outstanding"]; next?: MemberPaymentRow }) {
  const t = useT();
  if (!next || outstanding.count === 0) {
    return (
      <section aria-labelledby="due-h" className="relative overflow-hidden rounded-[24px] bg-deep p-5 text-deep-foreground ring-1 ring-primary/10">
        <NodePattern />
        <div className="relative flex items-center gap-4">
          <span className="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-success text-success-on">
            <CheckCircle2 className="h-8 w-8" strokeWidth={2} aria-hidden />
          </span>
          <div>
            <h2 id="due-h" className="font-display text-2xl font-extrabold leading-tight tracking-tight">
              {t("allPaid")}
            </h2>
            <p className="mt-1 text-base text-deep-foreground/80">{t("allPaidBody")}</p>
          </div>
        </div>
      </section>
    );
  }
  return (
    <section
      aria-labelledby="due-h"
      className="relative overflow-hidden rounded-[24px] bg-deep p-5 text-deep-foreground shadow-[0_18px_40px_-24px_rgb(var(--primary)/0.45)] ring-1 ring-primary/10"
    >
      <NodePattern />
      <div className="relative flex flex-col gap-4">
        <div className="flex items-center justify-between gap-3">
          <h2 id="due-h" className="font-sans text-base font-semibold text-deep-foreground/80">
            {t("amountDue")}
          </h2>
          {outstanding.overdueCount > 0 && (
            <span className="inline-flex h-7 items-center gap-1 rounded-full bg-danger-soft px-2.5 text-sm font-bold text-danger-foreground ring-1 ring-danger/25">
              <AlertTriangle className="h-4 w-4" strokeWidth={2} aria-hidden /> {fill(t("lateN"), { n: outstanding.overdueCount })}
            </span>
          )}
        </div>
        <div>
          <div className="figure font-display text-[52px] font-extrabold leading-none tracking-tight text-deep-foreground">{rm(outstanding.total)}</div>
          <div className="mt-2 text-base text-deep-foreground/80">
            {fill(t("openN"), { n: outstanding.count })}
            {outstanding.count > 1 && <span className="block">{t("payOldestFirst")}</span>}
          </div>
        </div>
        <div className="flex gap-2">
          <Link
            href={next.urls.review ?? next.urls.invoice}
            className="inline-flex h-14 flex-1 items-center justify-center gap-2 rounded-full bg-primary px-5 text-lg font-bold text-primary-foreground shadow-[0_8px_18px_-8px_rgb(var(--primary)/0.7)] transition-transform hover:bg-primary/90 active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-deep"
          >
            {t("payNow")} {rm(next.amount)}
          </Link>
          <Link
            href={next.urls.invoice}
            className="inline-flex h-14 items-center justify-center rounded-full border border-primary/45 bg-card/40 px-4 text-base font-semibold text-deep-foreground hover:bg-card/70 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            {t("viewInvoice")}
          </Link>
        </div>
      </div>
    </section>
  );
}

function ClubCard({ club, year, multi }: { club: HomeClub; year: number; multi: boolean }) {
  const t = useT();
  const { lang } = useLang();
  const slices = toSlices(club.fund.categories, { bm: "Lain-lain", en: "Others" });
  const open = club.summary.pending + club.summary.overdue;
  const hid = `club-${club.id}`;
  return (
    <section aria-labelledby={hid} className="flex flex-col gap-3">
      {multi && (
        <div className="flex items-end justify-between gap-2 px-1">
          <div className="min-w-0">
            <h2 id={hid} className="truncate font-display text-xl font-bold tracking-tight">
              {club.name}
            </h2>
            <p className="text-sm text-muted-foreground">{club.jobLevel}</p>
          </div>
        </div>
      )}
      <div className="rounded-[20px] border bg-card p-4">
        {!multi && (
          <h2 id={hid} className="sr-only">
            {club.name}
          </h2>
        )}
        <dl className="grid grid-cols-3 gap-2 text-center">
          <div className="rounded-2xl bg-mint px-2 py-2.5 text-mint-foreground">
            <dt className="text-sm">{t("paid")}</dt>
            <dd className="font-display text-xl font-bold">{club.summary.paid}</dd>
          </div>
          <div className={open > 0 ? "rounded-2xl bg-warning-soft px-2 py-2.5 text-warning-foreground" : "rounded-2xl bg-muted px-2 py-2.5"}>
            <dt className="text-sm">{t("stillToPay")}</dt>
            <dd className="font-display text-xl font-bold">{open}</dd>
          </div>
          <div className="rounded-2xl bg-accent px-2 py-2.5 text-accent-foreground">
            <dt className="text-sm">{t("total")}</dt>
            <dd className="money text-base font-bold leading-7">{rm(club.summary.totalPaid)}</dd>
          </div>
        </dl>
        <Link href={club.paymentsUrl} className="mt-2 inline-flex min-h-11 items-center gap-0.5 text-base font-semibold text-primary hover:underline">
          {t("viewPayments")} <ChevronRight className="h-5 w-5" aria-hidden />
        </Link>
      </div>

      <section aria-labelledby={`${hid}-fund`} className="flex flex-col gap-3 rounded-[20px] border bg-card p-4">
        <div>
          <h3 id={`${hid}-fund`} className="font-display text-xl font-bold tracking-tight">
            {t("moneyGoes")}
          </h3>
          <p className="text-sm text-muted-foreground">{fill(t("moneyGoesYear"), { y: year })}</p>
        </div>
        <div className="grid grid-cols-2 gap-2">
          <div className="rounded-2xl bg-accent px-3 py-2.5">
            <div className="text-sm text-accent-foreground">{t("collected")}</div>
            <Money sen={club.fund.collected} className="font-display text-lg font-bold" />
          </div>
          <div className="rounded-2xl bg-muted px-3 py-2.5">
            <div className="text-sm text-muted-foreground">{t("spent")}</div>
            <Money sen={club.fund.spent} className="font-display text-lg font-bold" />
          </div>
        </div>
        {slices.length > 0 ? (
          <CategoryBar slices={slices} lang={lang} interactive={false} />
        ) : (
          <p className="text-base text-muted-foreground">{t("noSpending")}</p>
        )}
        <p className="text-sm text-muted-foreground">{t("aggregatesOnly")}</p>
      </section>
    </section>
  );
}

export default function Home({ year, outstanding, upcoming, recentPaid, clubs, dashboardUrl, account }: Props) {
  const t = useT();
  const { lang } = useLang();
  const { auth } = usePage().props;
  const multi = clubs.length > 1;
  const firstName = (auth.user?.name ?? "").split(" ")[0];
  // "Bayaran" tab: the club of the oldest open fee (what the member most likely needs), else the first club.
  const payClub = clubs.find((c) => c.id === upcoming[0]?.clubId) ?? clubs[0];
  const navHrefs = { home: dashboardUrl, ...(payClub ? { payments: payClub.paymentsUrl } : {}) };

  return (
    <MemberShell
      tab="home"
      navHrefs={navHrefs}
      clubName={multi ? fill(t("clubsN"), { n: clubs.length }) : (clubs[0]?.name ?? "Club Portal")}
      title={`${t("hi")}, ${firstName}`}
    >
      <Head title={t("navHome")} />
      <FlashBanner />

      {clubs.length === 0 ? (
        <p className="rounded-[20px] border bg-card p-5 text-center text-base text-muted-foreground">{t("noClubs")}</p>
      ) : (
        <>
          <Hero outstanding={outstanding} next={upcoming[0]} />

          <SectionCard
            id="up-h"
            title={t("upcoming")}
            action={
              !multi && clubs[0] ? (
                <Link href={clubs[0].paymentsUrl} className="inline-flex min-h-11 items-center gap-0.5 text-base font-semibold text-primary hover:underline">
                  {t("viewAll")} <ChevronRight className="h-5 w-5" aria-hidden />
                </Link>
              ) : undefined
            }
          >
            {upcoming.length === 0 ? (
              <p className="px-4 pb-4 text-base text-muted-foreground">{t("nothingDue")}</p>
            ) : (
              <ul className="divide-y pb-1">
                {upcoming.map((p) => (
                  <li key={p.id}>
                    <PaymentRow p={p} showClub={multi} />
                  </li>
                ))}
              </ul>
            )}
          </SectionCard>

          <SectionCard id="rc-h" title={t("recent")}>
            {recentPaid.length === 0 ? (
              <p className="px-4 pb-4 text-base text-muted-foreground">{t("noRecent")}</p>
            ) : (
              <ul className="divide-y pb-1">
                {recentPaid.map((p) => (
                  <li key={p.id}>
                    <PaymentRow p={p} showClub={multi} />
                  </li>
                ))}
              </ul>
            )}
          </SectionCard>

          {multi && (
            <h2 className="-mb-2 px-1 pt-2 font-display text-2xl font-bold tracking-tight">{t("myClubs")}</h2>
          )}
          {clubs.map((c) => (
            <ClubCard key={c.id} club={c} year={year} multi={multi} />
          ))}
        </>
      )}

      <section aria-labelledby="acc-h" className="flex flex-col gap-3 rounded-[20px] border bg-card p-4">
        <h2 id="acc-h" className="font-display text-xl font-bold tracking-tight">
          {t("account")}
        </h2>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <span className="text-base font-semibold">{t("theme")}</span>
          <ThemeToggle size="lg" lang={lang} />
        </div>
        <a href={account.securityUrl} className="flex min-h-12 items-center gap-3 rounded-2xl px-1 text-base font-semibold hover:bg-muted">
          <KeyRound className="h-5 w-5 text-primary" strokeWidth={1.75} aria-hidden /> <span className="flex-1">{t("security")}</span>
          <ChevronRight className="h-5 w-5 text-muted-foreground" aria-hidden />
        </a>
        {account.adminUrl && (
          <a href={account.adminUrl} className="flex min-h-12 items-center gap-3 rounded-2xl px-1 text-base font-semibold hover:bg-muted">
            <ShieldCheck className="h-5 w-5 text-primary" strokeWidth={1.75} aria-hidden /> <span className="flex-1">{t("adminPortal")}</span>
            <ChevronRight className="h-5 w-5 text-muted-foreground" aria-hidden />
          </a>
        )}
        <Button variant="outline" size="lg" className="h-12 w-full rounded-full" type="button" onClick={() => router.post(account.logoutUrl)}>
          <LogOut aria-hidden /> {t("signOut")}
        </Button>
      </section>
    </MemberShell>
  );
}
