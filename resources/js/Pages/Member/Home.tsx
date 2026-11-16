import { AlertTriangle, CheckCircle2, ChevronRight, Hourglass, KeyRound, LogOut, PiggyBank, RefreshCw, ShieldCheck } from "lucide-react";
import type { ReactNode } from "react";
import { Head, Link, router, usePage } from "@inertiajs/react";
import { MemberShell } from "@/components/app/MemberShell";
import { InstallPrompt } from "@/components/app/InstallPrompt";
import { FlashBanner, PaymentRow, SectionCard, toSlices } from "@/components/app/MemberParts";
import { Money } from "@/components/app/Money";
import { CategoryBar } from "@/components/app/CategoryBar";
import { ThemeToggle } from "@/components/app/ThemeToggle";
import { Button } from "@/components/ui/button";
import { fmtDate, rm } from "@/lib/format";
import { fill, useLang, useT } from "@/lib/i18n";
import { cn } from "@/lib/utils";
import type { HomeClub, MemberPaymentRow, PayAllOffer } from "@/types/member";

interface Props {
  year: number;
  /**
   * Fees still owed (due or overdue). Fees whose transfer slip is being checked are only in verifyingCount;
   * fees with an FPX payment in progress (the bank has not confirmed it) only in processingCount / processingTotal.
   */
  outstanding: { total: number; count: number; overdueCount: number; verifyingCount: number; processingCount: number; processingTotal: number };
  upcoming: MemberPaymentRow[];
  recentPaid: MemberPaymentRow[];
  /** "Bayar semua": every fee owed in the club of the oldest one; null with fewer than two. */
  payAll: PayAllOffer | null;
  clubs: HomeClub[];
  dashboardUrl: string;
  account: { isAdmin: boolean; adminUrl: string | null; securityUrl: string; logoutUrl: string };
}

/** "1 menunggu pengesahan": fees whose slip the treasurer is still checking. */
function VerifyingLine({ n }: { n: number }) {
  const t = useT();
  return (
    <span className="inline-flex h-7 items-center gap-1 rounded-full bg-warning-soft px-2.5 text-sm font-bold text-warning-foreground ring-1 ring-warning/25" data-testid="verifying-line">
      <Hourglass className="h-4 w-4" strokeWidth={2} aria-hidden /> {fill(t("verifyingN"), { n })}
    </span>
  );
}

/** "1 dalam proses": fees with an FPX payment the bank has not confirmed yet. */
function ProcessingLine({ n }: { n: number }) {
  const t = useT();
  return (
    <span className="inline-flex min-h-7 items-center gap-1 rounded-full bg-warning-soft px-2.5 text-sm font-bold text-warning-foreground ring-1 ring-warning/25" data-testid="processing-line">
      <RefreshCw className="h-4 w-4" strokeWidth={2} aria-hidden /> {fill(t("processingN"), { n })}
    </span>
  );
}

/** The member's card: club, name and "Ahli" on a soft lavender gradient, with what is owed (or that nothing is) below. */
function MemberCard({ clubName, memberName, children }: { clubName: string; memberName: string; children: ReactNode }) {
  const t = useT();
  return (
    <section
      aria-labelledby="due-h"
      className="flex flex-col gap-4 rounded-[24px] bg-[linear-gradient(135deg,rgb(var(--deep))_0%,rgb(var(--accent))_100%)] p-5 text-deep-foreground shadow-[0_18px_40px_-28px_rgb(var(--primary)/0.5)] ring-1 ring-primary/15"
      data-testid="member-card"
    >
      <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-1 border-b border-primary/15 pb-3">
        <div className="min-w-0 flex-[1_1_10rem]">
          <p className="break-words text-sm font-semibold text-deep-foreground/80">{clubName}</p>
          <p className="break-words font-display text-lg font-bold leading-snug tracking-tight">{memberName}</p>
        </div>
        <span className="inline-flex min-h-7 items-center rounded-full bg-card/70 px-2.5 text-sm font-bold text-accent-foreground ring-1 ring-primary/20">{t("member")}</span>
      </div>
      {children}
    </section>
  );
}

const heroPrimary =
  "inline-flex min-h-14 items-center justify-center gap-2 rounded-full bg-primary px-5 py-3 text-center text-lg font-bold leading-tight text-primary-foreground shadow-[0_8px_18px_-8px_rgb(var(--primary)/0.7)] transition-transform hover:bg-primary/90 active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-deep";
const heroSecondary =
  "inline-flex min-h-14 flex-wrap items-center justify-center gap-x-1 rounded-full border border-primary/45 bg-card/40 px-4 py-3 text-center text-base font-semibold leading-snug text-deep-foreground hover:bg-card/70 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring";

function Hero({
  outstanding,
  next,
  payAll,
  multi,
  clubName,
  memberName,
}: {
  outstanding: Props["outstanding"];
  next?: MemberPaymentRow;
  payAll: PayAllOffer | null;
  multi: boolean;
  clubName: string;
  memberName: string;
}) {
  const t = useT();
  // Nothing else owed, but an FPX payment is still with the bank: checking it comes first, paying again second.
  if (outstanding.count === 0 && outstanding.processingCount > 0 && next?.status === "processing") {
    return (
      <MemberCard clubName={clubName} memberName={memberName}>
        <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
          <h2 id="due-h" className="font-sans text-base font-semibold text-deep-foreground/80">
            {t("heroProcessingTitle")}
          </h2>
          <ProcessingLine n={outstanding.processingCount} />
        </div>
        <div>
          <div className="break-words font-display text-[clamp(2.25rem,1rem+9vw,3.25rem)] font-extrabold leading-none tracking-tight text-deep-foreground [font-variant-numeric:proportional-nums]">
            {rm(outstanding.processingTotal)}
          </div>
          <p className="mt-2 text-base text-deep-foreground/80">{t("heroProcessingBody")}</p>
          {outstanding.verifyingCount > 0 && (
            <div className="mt-2 flex">
              <VerifyingLine n={outstanding.verifyingCount} />
            </div>
          )}
        </div>
        <div className="flex flex-col gap-3">
          <Link href={next.urls.billStatus ?? next.urls.invoice} className={cn(heroPrimary, "w-full")} data-testid="check-status">
            <RefreshCw className="h-5 w-5" strokeWidth={2} aria-hidden /> {t("checkPaymentStatus")}
          </Link>
          {next.urls.review && (
            <p className="text-base text-deep-foreground/85">
              <span>{t("payAgainWarn")}</span>{" "}
              <Link
                href={next.urls.review}
                className="inline-flex min-h-11 items-center rounded font-semibold text-deep-foreground underline underline-offset-4 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                data-testid="pay-again"
              >
                {t("payAgain")}
              </Link>
            </p>
          )}
        </div>
      </MemberCard>
    );
  }
  if (!next || outstanding.count === 0) {
    const verifying = outstanding.verifyingCount > 0;
    return (
      <MemberCard clubName={clubName} memberName={memberName}>
        <div className="flex items-center gap-4">
          <span
            className={
              verifying
                ? "flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-warning-soft text-warning-foreground"
                : "flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-success text-success-on"
            }
          >
            {verifying ? <Hourglass className="h-7 w-7" strokeWidth={2} aria-hidden /> : <CheckCircle2 className="h-8 w-8" strokeWidth={2} aria-hidden />}
          </span>
          <div className="min-w-0">
            <h2 id="due-h" className="font-display text-2xl font-extrabold leading-tight tracking-tight">
              {verifying ? t("heroVerifyingTitle") : t("allPaid")}
            </h2>
            <p className="mt-1 text-base text-deep-foreground/80">{verifying ? t("heroVerifyingBody") : t("allPaidBody")}</p>
          </div>
        </div>
      </MemberCard>
    );
  }
  return (
    <MemberCard clubName={clubName} memberName={memberName}>
      <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
        <h2 id="due-h" className="font-sans text-base font-semibold text-deep-foreground/80">
          {t("amountDue")}
        </h2>
        {outstanding.overdueCount > 0 && (
          <span className="inline-flex min-h-7 items-center gap-1 rounded-full bg-warning-soft px-2.5 text-sm font-bold text-warning-foreground ring-1 ring-warning/25" data-testid="late-line">
            <AlertTriangle className="h-4 w-4" strokeWidth={2} aria-hidden /> {fill(t("lateN"), { n: outstanding.overdueCount })}
          </span>
        )}
      </div>
      <div>
        {/* Scales with the reader's text size and wraps after "RM" rather than running off the card. */}
        <div className="break-words font-display text-[clamp(2.25rem,1rem+9vw,3.25rem)] font-extrabold leading-none tracking-tight text-deep-foreground [font-variant-numeric:proportional-nums]">
          {rm(outstanding.total)}
        </div>
        <div className="mt-2 text-base text-deep-foreground/80">
          {fill(t("openN"), { n: outstanding.count })}
          {outstanding.count > 1 && !payAll && <span className="block">{t("payOldestFirst")}</span>}
          {payAll && <span className="block">{fill(t("payAllHint"), { n: payAll.count })}</span>}
        </div>
        {(outstanding.verifyingCount > 0 || outstanding.processingCount > 0) && (
          <div className="mt-2 flex flex-wrap gap-2">
            {outstanding.processingCount > 0 && <ProcessingLine n={outstanding.processingCount} />}
            {outstanding.verifyingCount > 0 && <VerifyingLine n={outstanding.verifyingCount} />}
          </div>
        )}
      </div>
      {payAll ? (
        // Several fees: pay them all with one bill (one gateway charge), or just the oldest.
        <div className="flex flex-col gap-2">
          <Link
            href={payAll.url}
            className={heroPrimary}
            data-testid="pay-all"
            aria-describedby={multi ? "pay-all-club" : undefined}
          >
            {fill(t("payAllAmt"), { x: rm(payAll.totalSen) })}
          </Link>
          {multi && (
            <p id="pay-all-club" className="text-center text-sm text-deep-foreground/80">
              {fill(t("payAllInClub"), { c: payAll.clubName })}
            </p>
          )}
          <Link href={next.urls.review ?? next.urls.invoice} className={heroSecondary} data-testid="pay-one">
            {t("payOneOldest")} <span className="whitespace-nowrap">· {rm(next.amount)}</span>
          </Link>
        </div>
      ) : (
        <div className="flex flex-wrap gap-2">
          <Link href={next.urls.review ?? next.urls.invoice} className={cn(heroPrimary, "flex-[1_1_12rem]")}>
            {t("payNow")} {rm(next.amount)}
          </Link>
          <Link href={next.urls.invoice} className={cn(heroSecondary, "flex-[1_0_auto]")}>
            {t("viewInvoice")}
          </Link>
        </div>
      )}
    </MemberCard>
  );
}

function ClubCard({ club, multi }: { club: HomeClub; multi: boolean }) {
  const t = useT();
  const { lang } = useLang();
  const slices = toSlices(club.fund.categories, { bm: "Lain-lain", en: "Others" });
  const open = club.summary.due + club.summary.overdue + club.summary.processing;
  const hid = `club-${club.id}`;
  return (
    <section aria-labelledby={hid} className="flex flex-col gap-3">
      {multi && (
        <div className="flex items-end justify-between gap-2 px-1">
          <div className="min-w-0">
            <h2 id={hid} className="break-words font-display text-xl font-bold tracking-tight">
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
        <dl className="grid grid-cols-[repeat(auto-fit,minmax(min(100%,6rem),1fr))] gap-2 text-center">
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
          <p className="text-sm text-muted-foreground">
            {fill(t("moneyGoesWindow"), { from: fmtDate(club.fund.window.from, lang), to: fmtDate(club.fund.window.to, lang) })}
          </p>
        </div>
        <div className="grid grid-cols-[repeat(auto-fit,minmax(min(100%,9rem),1fr))] gap-2">
          <div className="rounded-2xl bg-accent px-3 py-2.5">
            <div className="text-sm text-accent-foreground">{t("collected")}</div>
            <Money sen={club.fund.collected} className="font-display text-lg font-bold" />
          </div>
          <div className="rounded-2xl bg-muted px-3 py-2.5">
            <div className="text-sm text-muted-foreground">{t("spent")}</div>
            <Money sen={club.fund.spent} className="font-display text-lg font-bold" />
          </div>
        </div>
        <p className="text-sm text-muted-foreground">{fill(t("spentSplit"), { a: rm(club.fund.expenses), b: rm(club.fund.welfare), c: rm(club.fund.charges) })}</p>
        {club.fund.fromReserves > 0 && (
          <p className="flex items-start gap-2 rounded-2xl bg-info-soft px-3 py-2.5 text-sm text-info-foreground">
            <PiggyBank className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
            <span>{fill(t("fromReserves"), { x: rm(club.fund.fromReserves) })}</span>
          </p>
        )}
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

export default function Home({ outstanding, upcoming, recentPaid, payAll, clubs, dashboardUrl, account }: Props) {
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
        <>
          <p className="rounded-[20px] border bg-card p-5 text-center text-base text-muted-foreground">{t("noClubs")}</p>
          <InstallPrompt />
        </>
      ) : (
        <>
          <Hero outstanding={outstanding} next={upcoming[0]} payAll={payAll} multi={multi} clubName={payClub?.name ?? ""} memberName={auth.user?.name ?? ""} />
          <InstallPrompt />

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
            <ClubCard key={c.id} club={c} multi={multi} />
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
        <Button variant="outline" size="lg" className="h-12 w-full rounded-full" type="button" onClick={() => {
            // Drop the encrypted page history (e.g. the 2FA page) before signing out.
            router.clearHistory();
            router.post(account.logoutUrl);
          }}>
          <LogOut aria-hidden /> {t("signOut")}
        </Button>
      </section>
    </MemberShell>
  );
}
